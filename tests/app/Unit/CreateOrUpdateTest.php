<?php

namespace DTApi\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Tests\TestCase;

class CreateOrUpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function it_creates_a_new_user()
    {
        $request = [
            'role' => config('app.customer_role_id'),
            'name' => 'John Doe',
            'email' => 'johndoe@example.com',
            'password' => 'password',
        ];

        $user = User::createOrUpdate(null, $request);

        $this->assertEquals($request['name'], $user->name);
        $this->assertEquals($request['email'], $user->email);
        $this->assertTrue($user->hasRole(config('app.customer_role_id')));
    }

    /**
     * @test
     */
    public function it_updates_an_existing_user()
    {
        $user = User::factory()->create();

        $request = [
            'name' => 'Test Irfan',
            'email' => 'irfan@test.com',
        ];

        $user = User::createOrUpdate($user->id, $request);

        $this->assertEquals($request['name'], $user->name);
        $this->assertEquals($request['email'], $user->email);
    }

    /**
     * @test
     */
    public function it_saves_the_user_meta_data()
    {
        $request = [
            'role' => config('app.customer_role_id'),
            'name' => 'Test Irfan',
            'email' => 'irfan@test.com',
            'password' => 'password',
            'translator_ex' => [1, 2],
        ];

        $user = User::createOrUpdate(null, $request);

        $userMeta = UserMeta::where('user_id', $user->id)->first();

        $this->assertEquals($request['translator_ex'], $userMeta->translator_ex);
    }

}
