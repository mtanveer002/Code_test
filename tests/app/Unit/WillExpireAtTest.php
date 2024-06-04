<?php

namespace DTApi\Unit;

use Illuminate\Http\Response;
use Tests\TestCase;

class WillExpireAtTest extends TestCase
{
    /**
     * @test
     */
    public function it_returns_the_due_time_if_the_difference_is_less_than_90_hours()
    {
        $dueTime = Carbon::now()->addHours(72);
        $createdAt = Carbon::now();

        $time = DateTimeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($dueTime->format('Y-m-d H:i:s'), $time);
    }

    /**
     * @test
     */
    public function it_returns_the_created_at_time_plus_90_minutes_if_the_difference_is_less_than_24_hours()
    {
        $dueTime = Carbon::now()->addHours(23);
        $createdAt = Carbon::now();

        $time = DateTimeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($createdAt->addMinutes(90)->format('Y-m-d H:i:s'), $time);
    }

    /**
     * @test
     */
    public function it_returns_the_created_at_time_plus_16_hours_if_the_difference_is_between_24_and_72_hours()
    {
        $dueTime = Carbon::now()->addHours(60);
        $createdAt = Carbon::now();

        $time = DateTimeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($createdAt->addHours(16)->format('Y-m-d H:i:s'), $time);
    }

    /**
     * @test
     */
    public function it_returns_the_due_time_minus_48_hours_if_the_difference_is_greater_than_72_hours()
    {
        $dueTime = Carbon::now()->addHours(120);
        $createdAt = Carbon::now();

        $time = DateTimeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($dueTime->subHours(48)->format('Y-m-d H:i:s'), $time);
    }
}
