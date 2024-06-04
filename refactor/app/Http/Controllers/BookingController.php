<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $bookingRepository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->bookingRepository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $userId = $request->get('user_id');
        $userType = Auth::user()->userType;
        $adminRoleId = config('app.admin_role_id');
        $superAdminRoleId = config('app.super_admin_role_id');

        if ($userId) {
            $response = $this->bookingRepository->getUsersJobs($userId);
        } elseif (in_array($userType, [$adminRoleId, $superAdminRoleId])) {
            $response = $this->bookingRepository->getAll($request);
        } else {
            $response = []; // or handle this case appropriately
        }

        return response($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->bookingRepository->with('translatorJobRole.user')->find($id);

        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response(['error' => 'Unauthorized'], 401);
        }

        $response = $this->bookingRepository->store($user, $request->all());

        return response($response);

    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $currentUser = Auth::user();
        if (!$currentUser) {
            return response(['error' => 'Unauthorized'], 401);
        }
        $response = $this->bookingRepository->updateJob($id, array_except($request->all(), ['_token', 'submit']), $currentUser);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $response = $this->bookingRepository->storeJobEmail($request->all());
        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        $userId = $request->get('user_id');
        if ($userId) {
            return response($this->bookingRepository->getUsersJobsHistory($userId, $request));
        }

        return null;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $response = $this->bookingRepository->acceptJob($request->all(), $Auth::user());

        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $response = $this->bookingRepository->acceptJobWithId($request->get('job_id'), Auth::user());

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $response = $this->bookingRepository->cancelJobAjax($request->all(), Auth::user());

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $response = $this->bookingRepository->endJob($request->all());

        return response($response);

    }

    public function customerNotCall(Request $request)
    {
        $response = $this->bookingRepository->customerNotCall($request->all());

        return response($response);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $response = $this->bookingRepository->getPotentialJobs(Auth::user());
        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        return response('Record updated!');
    }

    public function reopen(Request $request)
    {
        $response = $this->bookingRepository->reopen($request->all());

        return response($response);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);
        $jobData = $this->bookingRepository->jobToData($job);
        $this->bookingRepository->sendNotificationTranslator($job, $jobData, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);

        try {
            $this->bookingRepository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}
