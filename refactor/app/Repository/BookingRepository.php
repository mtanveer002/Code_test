<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;
    protected $pushLogger;

    /**
     * @param Job $job
     * @param MailerInterface $mailer
     */
    function __construct(Job $job, MailerInterface $mailer)
    {
        parent::__construct($job);
        $this->mailer = $mailer;
        $this->initializeLoggers();
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobs($userId): array
    {
        $currentUser = User::find($userId);

        if(!$currentUser) {
            return [];
        }

        $userType = '';
        $emergencyJobs = [];
        $normalJobs = [];
        if ($currentUser->is('customer')) {
            $jobs = $currentUser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
            $userType = 'customer';
        } elseif ($currentUser->is('translator')) {
            $jobs = Job::getTranslatorJobs($currentUser->id, 'new');
            $jobs = $jobs->pluck('jobs')->toArray();
            $userType = 'translator';
        }
        if ($jobs) {
            foreach ($jobs as $jobItem) {
                if ($jobItem->immediate == 'yes') {
                    $emergencyJobs[] = $jobItem;
                } else {
                    $normalJobs[] = $jobItem;
                }
            }
            $normalJobs = collect($normalJobs)->each(function ($item) use ($userId) {
                $item['userCheck'] = Job::checkParticularJob($userId, $item);
            })->sortBy('due')->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'currentUser' => $currentUser,
            'userType' => $userType
        ];
    }

    /**
     * @param $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($userId, Request $request): array
    {
        $pageNumber = $request->get('page', 1);
        $currentUser = User::find($userId);

        if (!$currentUser) {
            return [];
        }

        $emergencyJobs = [];
        $usertype = '';
        $jobs = [];
        $normalJobs = [];
        $totalPages = 0;

        if ($currentUser->is('customer')) {
            $jobs = $currentUser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            $usertype = 'customer';
        } elseif ($currentUser->is('translator')) {
            $jobIds = Job::getTranslatorJobsHistoric($currentUser->id, 'historic', $pageNumber);
            $totalJobs = $jobIds->total();
            $totalPages = ceil($totalJobs / 15);

            $usertype = 'translator';
            $jobs = $jobIds;
            $normalJobs = $jobIds;
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'currentUser' => $currentUser,
            'usertype' => $usertype,
            'totalPages' => $totalPages,
            'pageNumber' => $pageNumber
        ];
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumerType;

        if ($user->userType != env('CUSTOMER_ROLE_ID')) {
            return [
                'status' => 'fail',
                'message' => "Translator can not create booking",
            ];
        }

        $currentUser = $user;
        $requiredFields = [
            'from_language_id' => "from_language_id",
            'duration' => "duration"
        ];

        foreach ($requiredFields as $field => $fieldName) {
            if (empty($data[$field])) {
                return [
                    'status' => 'fail',
                    'message' => "Du måste fylla in alla fält",
                    'field_name' => $fieldName,
                ];
            }
        }

        if ($data['immediate'] == 'no') {
            $immediateFields = [
                'due_date' => "due_date",
                'due_time' => "due_time"
            ];

            foreach ($immediateFields as $field => $fieldName) {
                if (empty($data[$field])) {
                    return [
                        'status' => 'fail',
                        'message' => "Du måste fylla in alla fält",
                        'field_name' => $fieldName,
                    ];
                }
            }

            if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                return [
                    'status' => 'fail',
                    'message' => "Du måste göra ett val här",
                    'field_name' => "customer_phone_type",
                ];
            }
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $response['customer_physical_type'] = $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        if ($data['immediate'] == 'yes') {
            $due_carbon = Carbon::now()->addMinute($immediateTime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $response['type'] = 'regular';

            if ($due_carbon->isPast()) {
                return [
                    'status' => 'fail',
                    'message' => "Can't create booking in past",
                ];
            }
        }

        $jobForMapping = [
            'male' => 'gender',
            'female' => 'gender',
            'normal' => 'certified',
            'certified' => 'certified',
            'certified_in_law' => 'certified',
            'certified_in_helth' => 'certified'
        ];

        foreach ($jobForMapping as $key => $value) {
            if (in_array($key, $data['job_for'])) {
                $data[$value] = $key;
            }
        }

        $combinedCertifiedMapping = [
            ['normal', 'certified', 'both'],
            ['normal', 'certified_in_law', 'n_law'],
            ['normal', 'certified_in_helth', 'n_health']
        ];

        foreach ($combinedCertifiedMapping as [$first, $second, $result]) {
            if (in_array($first, $data['job_for']) && in_array($second, $data['job_for'])) {
                $data['certified'] = $result;
            }
        }

        $jobTypeMapping = [
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid'
        ];

        $data['job_type'] = $jobTypeMapping[$consumerType] ?? null;
        $data['b_created_at'] = date('Y-m-d H:i:s');

        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }

        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $currentUser->jobs()->create($data);

        $response['status'] = 'success';
        $response['id'] = $job->id;
        $data['job_for'] = [];

        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        $data = $this->getArr2($job, $data);
        $data['customer_town'] = $currentUser->userMeta->city;
        $data['customer_type'] = $currentUser->userMeta->customer_type;

        //Event::fire(new JobWasCreated($job, $data, '*'));
        //$this->sendNotificationToSuitableTranslators($job->id, $data, '*'); // send Push for New job posting

        return $response;
    }


    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $job = Job::findOrFail($data['user_email_job_id'] ?? null);
        $user = $job->user()->first();

        $job->userEmail = $data['user_email'] ?? null;
        $job->reference = $data['reference'] ?? '';

        if (isset($data['address'])) {
            $job->address = $data['address'] ?: optional($user->userMeta)->address;
            $job->instructions = $data['instructions'] ?: optional($user->userMeta)->instructions;
            $job->town = $data['town'] ?: optional($user->userMeta)->city;
        }

        $job->save();

        $email = $job->userEmail ?: $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        $this->mailer->send($email, $name, $subject, 'emails.job-created', [
            'user' => $user,
            'job' => $job,
        ]);

        $response = [
            'type' => $data['user_type'],
            'job' => $job,
            'status' => 'success',
        ];

        Event::fire(new JobWasCreated($job, $this->jobToData($job), '*'));

        return $response;
    }


    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = $this->getArr($job);            // save job's information to data for sending Push
        $data = $this->getArr3($job, $data);
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $data = $this->getArr1($job, $data);
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;

    }


    /**
     * Function to get all Potential jobs of user with his ID
     * @param $userId
     * @return array
     */
    public function getPotentialJobIdsWithUserId($userId): array
    {
        $userMeta = UserMeta::where('user_id', $userId)->firstOrFail();
        $translatorType = $userMeta->translatorType;
        $jobType = $translatorType === 'professional' ? 'paid' : ($translatorType === 'rwstranslator' ? 'rws' : 'unpaid');

        $languages = UserLanguages::where('user_id', $userId)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translatorLevel;

        $jobIds = Job::getJobs($userId, $jobType, 'pending', $languages, $gender, $translatorLevel);

        $jobIds = $jobIds->filter(function ($job) use ($userId) {
            $jobUserId = $job->userId;
            $checkTown = Job::checkTowns($jobUserId, $userId);
            return !($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checkTown;
        });

        return TeHelper::convertJobIdsInObjs($jobIds);
    }


    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $excludeUserId, array $data = [])
    {
        $suitableTranslators = collect([]);

        User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $excludeUserId)
            ->each(function ($user) use ($job, $data, &$suitableTranslators) {
                if (!$this->sendingPushNeeded($user->id)) {
                    return;
                }

                $notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') {
                    return;
                }

                $jobs = $this->getPotentialJobIdsWithUserId($user->id);
                foreach ($jobs as $potentialJob) {
                    if ($job->id == $potentialJob->id) {
                        $jobTorTranslator = Job::assignedToPaticularTranslator($user->id, $potentialJob->id);
                        if ($jobTorTranslator == 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($user->id, $potentialJob);
                            if ($jobChecker != 'userCanNotAcceptJob') {
                                $translator_array = $this->pushDelayNeeded($user->id) ? 'delpay_translator_array' : 'translator_array';
                                $suitableTranslators[$translator_array][] = $user;
                            }
                        }
                    }
                }
            });

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = $data['immediate'] == 'no' ? 'Ny bokning för ' : 'Ny akutbokning för ';
        $msg_contents .= $data['language'] . 'tolk ' . $data['duration'] . 'min';
        $msg_text = ["en" => $msg_contents];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$suitableTranslators['translator_array'], $suitableTranslators['delpay_translator_array'], $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($suitableTranslators['translator_array'], $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($suitableTranslators['delpay_translator_array'], $job->id, $data, $msg_text, true);
    }


    /**
     * Sends SMS to translators and returns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job): int
    {
        $jobData = Job::with('user.meta')->find($job->id);

        $date = date('d.m.Y', strtotime($jobData->due));
        $time = date('H:i', strtotime($jobData->due));
        $duration = $this->convertToHoursMins($jobData->duration);
        $jobId = $jobData->id;
        $city = $jobData->city ?? $jobData->user->meta->city;

        $messageTemplate = trans('sms.phone_job', [
            'date' => $date,
            'time' => $time,
            'duration' => $duration,
            'town' => $city,
            'jobId' => $jobId
        ]);

        // send messages via sms handler
        $translators = $this->getPotentialTranslators($jobData);
        $messages = [];

        foreach ($translators as $translator) {
            // determine message based on job type
            $message = $messageTemplate;
            if ($jobData->customer_physical_type == 'yes' && $jobData->customer_phone_type == 'no') {
                $message = trans('sms.physical_job', [
                    'date' => $date,
                    'time' => $time,
                    'town' => $city,
                    'duration' => $duration,
                    'jobId' => $jobId
                ]);
            }

            // queue messages for sending
            $messages[] = [
                'to' => $translator->mobile,
                'message' => $message
            ];
        }

        // send batched messages and log status
        $status = SendSMSHelper::sendBatch(env('SMS_NUMBER'), $messages);
        Log::info('Send SMS to translators, status: ' . print_r($status, true));

        return count($translators);
    }


    /**
     * Function to delay the push
     * @param $userId
     * @return bool
     */
    public function pushDelayNeeded($userId): bool
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $notGetNightTime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
        if ($notGetNightTime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if it needs to send the push
     * @param $userId
     * @return bool
     */
    public function sendingPushNeeded($userId): bool
    {
        $notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
        if ($notGetNotification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $jobId, $data, $msgText, $isNeedDelay)
    {
        $this->pushLogger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedDelay]);

        $appEnv = config('app.app_env');
        $onesignalAppID = $appEnv == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", $appEnv == 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $jobId;
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            $androidSound = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
            $iosSound = $data['immediate'] == 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';
        }

        $fields = [
            'app_id' => $onesignalAppID,
            'tags' => json_decode($userTags),
            'data' => $data,
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msgText,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $androidSound,
            'ios_sound' => $iosSound,
        ];
        if ($isNeedDelay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fieldsJson = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsJson);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $this->pushLogger->addInfo('Push send for job ' . $jobId . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $translatorType = '';
        switch ($job->jobType) {
            case 'paid':
                $translatorType = 'professional';
                break;
            case 'rws':
                $translatorType = 'rwstranslator';
                break;
            case 'unpaid':
                $translatorType = 'volunteer';
                break;
            default:
                // Handle unexpected job types
                break;
        }

        $translatorLevel = [];
        if (!empty($job->certified)) {
            switch ($job->certified) {
                case 'yes':
                case 'both':
                    $translatorLevel = [
                        'Certified',
                        'Certified with specialisation in law',
                        'Certified with specialisation in health care'
                    ];
                    break;
                case 'law':
                case 'n_law':
                    $translatorLevel[] = 'Certified with specialisation in law';
                    break;
                case 'health':
                case 'n_health':
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case 'normal':
                    $translatorLevel = [
                        'Layman',
                        'Read Translation courses'
                    ];
                    break;
                default:
                    // Handle unexpected certification values
                    break;
            }
        } else {
            // Default translator level if certification is not provided
            $translatorLevel = [
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care',
                'Layman',
                'Read Translation courses'
            ];
        }

        $blacklistedTranslators = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();

        return User::getPotentialUsers($translatorType, $job->from_language_id, $job->gender, $translatorLevel, $blacklistedTranslators);
    }


    /**
     * @param $id
     * @param $data
     * @param $currentUser
     * @return string[]
     */
    public function updateJob($id, $data, $currentUser)
    {
        $job = Job::find($id);
        $logData = [];

        $currentTranslator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($currentTranslator))
            $currentTranslator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $logData[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        $langChanged = false;
        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $logData[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $oldTime);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $job->from_language_id);
            }
        }

        $this->logger->addInfo('USER #' . $currentUser->id . '(' . $currentUser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $logData);

        return ['Updated'];
    }

    /**
     * @param $user
     * @param $job
     * @param $language
     * @param $duration
     * @return void
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->sendingPushNeeded($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->pushDelayNeeded($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $currentTranslator
     * @param $newTranslator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $newTranslator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job' => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($currentTranslator) {
            $user = $currentTranslator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $newTranslator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $oldTime
     */
    public function sendChangedDateNotification($job, $oldTime)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_time' => $oldTime
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user' => $translator,
            'job' => $job,
            'old_time' => $oldTime
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $oldLang
     */
    public function sendChangedLangNotification($job, $oldLang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $oldLang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }


    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = $this->getArr($job);
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;
        $data = $this->getArr1($job, $data);
        $data = $this->getArr2($job, $data);
        $this->sendNotificationTranslator($job, $data, (array)'*');   // send Push all sutiable translators
    }

    /**
     * @param $job
     * @return array
     */
    public function getArr($job): array
    {
        $data = array();            // save job's information to data for sending Push
        return $this->getArr3($job, $data);
    }

    /**
     * @param $job
     * @param array $data
     * @return array
     */
    public function getArr1($job, array $data): array
    {
        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        return $data;
    }

    /**
     * @param $job
     * @param array $data
     * @return array
     */
    public function getArr2($job, array $data): array
    {
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        return $data;
    }

    /**
     * @param $job
     * @param array $data
     * @return array
     */
    public function getArr3($job, array $data): array
    {
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        return $data;
    }

    /**
     * @param $tr
     * @param $job
     * @param string $session_time
     * @param $completeddate
     * @return void
     */
    public function sendUseEmail($tr, $job, string $session_time, $completeddate)
    {
        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user): array
    {

        $currentUser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $currentUser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($currentUser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }
                $data = [
                    'user' => $user,
                    'job' => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($currentUser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;

    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $currentUser): array
    {
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $currentUser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($currentUser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job' => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->sendingPushNeeded($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->pushDelayNeeded($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = ['status' => 'fail', 'message' => 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!'];
        $job = Job::findOrFail($data['job_id']);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $hoursUntilDue = Carbon::now()->diffInHours($job->due);
        $isBefore24Hours = $hoursUntilDue >= 24;

        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = $isBefore24Hours ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();

            Event::fire(new JobWasCanceled($job));

            $response = ['status' => 'success', 'jobstatus' => 'success'];

            if ($translator) {
                $this->notifyTranslator($translator, $job);
            }
        } elseif ($hoursUntilDue > 24) {
            $customer = $job->user()->first();
            if ($customer) {
                $this->notifyCustomer($customer, $job);
            }

            $job->status = 'pending';
            $job->created_at = now();
            $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
            $job->save();

            Job::deleteTranslatorJobRel($translator->id, $job->id);

            $data = $this->jobToData($job);
            $this->sendNotificationTranslator($job, $data, $translator->id);

            $response = ['status' => 'success'];
        }

        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($currentUser)
    {
        $currentUserMeta = $currentUser->userMeta;
        $translatorType = $currentUserMeta->translator_type;
        switch ($translatorType) {
            case 'professional':
                $jobType = 'paid';
                break;
            case 'rwstranslator':
                $jobType = 'rws';
                break;
            case 'volunteer':
            default:
                $jobType = 'unpaid';
                break;
        }

        $userLanguages = UserLanguages::where('user_id', $currentUser->id)->pluck('lang_id')->all();
        $gender = $currentUserMeta->gender;
        $translatorLevel = $currentUserMeta->translator_level;
        $jobs = Job::getJobs($currentUser->id, $jobType, 'pending', $userLanguages, $gender, $translatorLevel);

        foreach ($jobs as $key => $job) {
            $jobUserId = $job->user_id;
            $job->specific_job = Job::assignedToParticularTranslator($currentUser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($currentUser->id, $job);
            $checkTown = Job::checkTowns($jobUserId, $currentUser->id);

            if ($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') {
                unset($jobs[$key]);
            }

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '') && $job->customer_physical_type === 'yes' && !$checkTown) {
                unset($jobs[$key]);
            }
        }

        return $jobs;
    }


    public function endJob($postData)
    {
        $completedDate = now();
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if ($jobDetail->status != 'started') {
            return ['status' => 'success'];
        }

        $startDate = date_create($jobDetail->due);
        $endDate = date_create($completedDate);
        $interval = $startDate->diff($endDate)->format('%H:%I:%S');

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;

        $user = $jobDetail->user()->first();
        $email = $jobDetail->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $sessionTime = $startDate->diff($endDate)->format('%H tim %I min');

        $data = [
            'user' => $user,
            'job' => $jobDetail,
            'session_time' => $sessionTime,
            'for_text' => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $jobDetail->save();

        $translatorJobRel = $jobDetail->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

        event(new SessionEnded($jobDetail, ($postData['user_id'] == $jobDetail->user_id) ? $translatorJobRel->user_id : $jobDetail->user_id));
        $this->sendUseEmail($translatorJobRel, $jobDetail, $sessionTime, $completedDate);
        $translatorJobRel->completed_by = $postData['user_id'];
        $translatorJobRel->save();

        return ['status' => 'success'];
    }



    public function customerNotCall($postData): array
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completedDate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $currentUser = Auth::user();
        $consumerType = $currentUser->consumerType;
        $isSuperAdmin = $currentUser && $currentUser->user_type == env('SUPERADMIN_ROLE_ID');
        $allJobs = Job::query();

        $this->applyCommonFilters($allJobs, $requestData);

        if ($isSuperAdmin) {
            $this->applySuperAdminFilters($allJobs, $requestData);
        } else {
            $this->applyUserFilters($allJobs, $requestData, $consumerType);
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }

        return $allJobs;
    }

    public function reopen($request): array
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);

        if (!$job) {
            return ["Job not found!"];
        }

        $now = now();
        $willExpireAt = TeHelper::willExpireAt($job->due, $now);

        $datareopen = [
            'status' => $job->status != 'timedout' ? 'pending' : 'pending',
            'created_at' => $now,
            'will_expire_at' => $willExpireAt
        ];

        $affectedRows = Job::where('id', $jobid)
            ->update($datareopen);

        if ($job->status == 'timedout') {
            $jobData = [
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
                'will_expire_at' => $willExpireAt,
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
                'admin_comments' => 'This booking is a reopening of booking #' . $jobid,
                'user_id' => $userid,
                'job_id' => $jobid,
                'cancel_at' => $now
            ];

            $affectedRows = Job::create($jobData);
            $new_jobid = $affectedRows->id;
        } else {
            $new_jobid = $jobid;
        }

        Translator::where('job_id', $jobid)
            ->whereNull('cancel_at')
            ->update(['cancel_at' => $now]);

        $translatorData = [
            'created_at' => $now,
            'updated_at' => $now,
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => $now
        ];

        $translator = Translator::create($translatorData);

        if ($affectedRows) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    private function initializeLoggers()
    {
        $this->logger = new Logger('admin_logger');
        $logPath = storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log');
        $this->logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $this->pushLogger = new Logger('push_logger');
        $pushLogPath = storage_path('logs/push/laravel-' . date('Y-m-d') . '.log');
        $this->pushLogger->pushHandler(new StreamHandler($pushLogPath, Logger::DEBUG));
        $this->pushLogger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator): array
    {
        $old_status = $job->status;
        $statusChanged = false;
        $log_data = [];
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimeoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
            }
        }
        return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimeoutStatus($job, $data, $changedTranslator): bool
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, ['*']);   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        //        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator): bool
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
    }

    private function applyUserFilters($allJobs, $requestData, $consumerType)
    {
        if ($consumerType == 'RWS') {
            $allJobs->where('job_type', '=', 'rws');
        } else {
            $allJobs->where('job_type', '=', 'unpaid');
        }

        if (isset($requestData['feedback']) && $requestData['feedback'] !== 'false') {
            $allJobs->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });

            if (isset($requestData['count']) && $requestData['count'] !== 'false') {
                return ['count' => $allJobs->count()];
            }
        }
    }

    private function applyDateFilters($allJobs, $column, $requestData)
    {
        if (isset($requestData['from']) && $requestData['from'] != "") {
            $allJobs->where($column, '>=', $requestData["from"]);
        }
        if (isset($requestData['to']) && $requestData['to'] != "") {
            $to = $requestData["to"] . " 23:59:00";
            $allJobs->where($column, '<=', $to);
        }
        $allJobs->orderBy($column, 'desc');
    }

    private function applyAdditionalFilters($allJobs, $requestData)
    {
        $filters = [
            'physical' => 'customer_physical_type',
            'phone' => 'customer_phone_type',
            'flagged' => 'flagged',
            'distance' => 'distance',
            'salary' => 'user.salaries',
            'consumerType' => 'user.userMeta.consumerType',
            'booking_type' => 'booking_type'
        ];

        foreach ($filters as $key => $column) {
            if (isset($requestData[$key])) {
                if ($key == 'distance' && $requestData[$key] == 'empty') {
                    $allJobs->whereDoesntHave($column);
                } elseif ($key == 'salary' && $requestData[$key] == 'yes') {
                    $allJobs->whereDoesntHave($column);
                } elseif ($key == 'consumerType') {
                    $allJobs->whereHas($column, function ($q) use ($requestData) {
                        $q->where('consumerType', $requestData['consumerType']);
                    });
                } elseif ($key == 'booking_type') {
                    if ($requestData[$key] == 'physical') {
                        $allJobs->where('customer_physical_type', 'yes');
                    } elseif ($requestData[$key] == 'phone') {
                        $allJobs->where('customer_phone_type', 'yes');
                    }
                } else {
                    $allJobs->where($column, $requestData[$key]);
                }
            }
        }
    }

    private function applyCommonFilters($allJobs, $requestData)
    {
        if (isset($requestData['id']) && $requestData['id'] != '') {
            if (is_array($requestData['id'])) {
                $allJobs->whereIn('id', $requestData['id']);
            } else {
                $allJobs->where('id', $requestData['id']);
            }
            $requestData = array_only($requestData, ['id']);
        }

        if (isset($requestData['lang']) && $requestData['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestData['lang']);
        }

        if (isset($requestData['status']) && $requestData['status'] != '') {
            $allJobs->whereIn('status', $requestData['status']);
        }

        if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
            $allJobs->whereIn('job_type', $requestData['job_type']);
        }

        if (isset($requestData['filter_timetype'])) {
            $filterType = $requestData['filter_timetype'];
            if ($filterType == "created") {
                $this->applyDateFilters($allJobs, 'created_at', $requestData);
            } elseif ($filterType == "due") {
                $this->applyDateFilters($allJobs, 'due', $requestData);
            }
        }

        if (isset($requestData['customer_email']) && !empty($requestData['customer_email'])) {
            $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
            if ($user) {
                $allJobs->where('user_id', '=', $user->id);
            }
        }
    }

    private function applySuperAdminFilters($allJobs, $requestData)
    {
        if (isset($requestData['feedback']) && $requestData['feedback'] !== 'false') {
            $allJobs->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });

            if (isset($requestData['count']) && $requestData['count'] !== 'false') {
                return ['count' => $allJobs->count()];
            }
        }

        if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
            $allJobs->where('expired_at', '>=', $requestData['expired_at']);
        }

        if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
            $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
        }

        if (isset($requestData['customer_email']) && !empty($requestData['customer_email'])) {
            $users = DB::table('users')->whereIn('email', $requestData['customer_email'])->get();
            if ($users) {
                $allJobs->whereIn('user_id', $users->pluck('id')->all());
            }
        }

        if (isset($requestData['translator_email']) && !empty($requestData['translator_email'])) {
            $users = DB::table('users')->whereIn('email', $requestData['translator_email'])->get();
            if ($users) {
                $allJobIDs = DB::table('translator_job_rel')
                ->whereNull('cancel_at')
                ->whereIn('user_id', $users->pluck('id')->all())
                    ->pluck('job_id');
                $allJobs->whereIn('id', $allJobIDs);
            }
        }

        $this->applyAdditionalFilters($allJobs, $requestData);
    }

    private function notifyTranslator($translator, $job)
    {
        $data = [
            'notification_type' => 'job_cancelled',
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
        ];

        if ($this->sendingPushNeeded($translator->id)) {
            $users_array = [$translator];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->pushDelayNeeded($translator->id));
        }
    }

    private function notifyCustomer($customer, $job)
    {
        $data = [
            'notification_type' => 'job_cancelled',
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
        ];

        if ($this->sendingPushNeeded($customer->id)) {
            $users_array = [$customer];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->pushDelayNeeded($customer->id));
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data): bool
    {
        if ($data['status'] == 'timedout') {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data): bool
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job): array
    {
        $translatorChanged = false;
        $log_data = [];
        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
            }
            $translatorChanged = true;
        }
        if ($translatorChanged)
            return ['translatorChanged' => true, 'new_translator' => $new_translator ?? '', 'log_data' => $log_data];
        return ['translatorChanged' => false];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due): array
    {
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            return ['dateChanged' => true, 'log_data' => $log_data];
        }
        return ['dateChanged' => false];
    }


    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users): string
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param int $time
     * @param string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin'): string
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }

}