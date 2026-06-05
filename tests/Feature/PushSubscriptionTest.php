<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // VAPID public key
    // -------------------------------------------------------------------------

    public function test_vapid_key_endpoint_returns_public_key(): void
    {
        $user = $this->makeUser();

        // Set a fake VAPID key for the test
        config(['webpush.vapid.public_key' => 'test-vapid-public-key-abc123']);

        $response = $this->actingAs($user)->getJson(route('push.vapid-key'));

        $response->assertOk()
            ->assertJson(['publicKey' => 'test-vapid-public-key-abc123']);
    }

    public function test_vapid_key_endpoint_requires_authentication(): void
    {
        $response = $this->getJson(route('push.vapid-key'));

        $response->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Subscribe
    // -------------------------------------------------------------------------

    public function test_user_can_subscribe_to_push_notifications(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson(route('push.subscribe'), [
            'endpoint'        => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys'            => [
                'p256dh' => 'BPc1234examplePublicKey',
                'auth'   => 'authSecret123',
            ],
            'contentEncoding' => 'aesgcm',
        ]);

        $response->assertCreated()
            ->assertJson(['status' => 'subscribed']);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id'  => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);
    }

    public function test_subscribe_updates_existing_subscription_for_same_endpoint(): void
    {
        $user = $this->makeUser();

        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';

        // First subscribe
        $this->actingAs($user)->postJson(route('push.subscribe'), [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'oldKey', 'auth' => 'oldAuth'],
        ]);

        // Subscribe again with same endpoint but new keys
        $this->actingAs($user)->postJson(route('push.subscribe'), [
            'endpoint' => $endpoint,
            'keys'     => ['p256dh' => 'newKey', 'auth' => 'newAuth'],
        ]);

        // Should be one record, not two
        $this->assertSame(1, PushSubscription::count());

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id'    => $user->id,
            'endpoint'   => $endpoint,
            'public_key' => 'newKey',
            'auth_token' => 'newAuth',
        ]);
    }

    public function test_subscribe_requires_endpoint(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson(route('push.subscribe'), [
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_subscribe_requires_authentication(): void
    {
        $response = $this->postJson(route('push.subscribe'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys'     => ['p256dh' => 'key', 'auth' => 'auth'],
        ]);

        $response->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Unsubscribe
    // -------------------------------------------------------------------------

    public function test_user_can_unsubscribe_from_push_notifications(): void
    {
        $user     = $this->makeUser();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';

        PushSubscription::create([
            'user_id'    => $user->id,
            'endpoint'   => $endpoint,
            'public_key' => 'someKey',
            'auth_token' => 'someAuth',
        ]);

        $response = $this->actingAs($user)->deleteJson(route('push.unsubscribe'), [
            'endpoint' => $endpoint,
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'unsubscribed']);

        $this->assertDatabaseMissing('push_subscriptions', [
            'user_id'  => $user->id,
            'endpoint' => $endpoint,
        ]);
    }

    public function test_user_cannot_unsubscribe_another_users_subscription(): void
    {
        $ownerUser  = $this->makeUser();
        $attackUser = $this->makeUser();
        $endpoint   = 'https://fcm.googleapis.com/fcm/send/abc123';

        PushSubscription::create([
            'user_id'    => $ownerUser->id,
            'endpoint'   => $endpoint,
            'public_key' => 'someKey',
            'auth_token' => 'someAuth',
        ]);

        // Attacker tries to unsubscribe owner's endpoint
        $this->actingAs($attackUser)->deleteJson(route('push.unsubscribe'), [
            'endpoint' => $endpoint,
        ]);

        // Owner's subscription must still exist
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id'  => $ownerUser->id,
            'endpoint' => $endpoint,
        ]);
    }

    public function test_unsubscribe_requires_authentication(): void
    {
        $response = $this->deleteJson(route('push.unsubscribe'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);

        $response->assertUnauthorized();
    }

    public function test_unsubscribe_requires_endpoint(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->deleteJson(route('push.unsubscribe'), []);

        $response->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(): User
    {
        return User::create([
            'name'                => 'Test User',
            'email'               => 'user-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
    }
}
