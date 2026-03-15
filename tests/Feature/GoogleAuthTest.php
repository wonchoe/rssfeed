<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'google-client-id');
        config()->set('services.google.client_secret', 'google-client-secret');
        config()->set('services.google.redirect', 'http://rss.cursor.style/auth/google/callback');
    }

    public function test_login_and_register_pages_show_google_auth_cta_when_configured(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Continue with Google');

        $this->get('/register')
            ->assertOk()
            ->assertSee('Continue with Google');
    }

    public function test_google_redirect_route_forwards_user_to_google(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser());

        $this->get('/auth/google/redirect')
            ->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_google_callback_creates_a_new_account_and_logs_the_user_in(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser());

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $user = User::query()->firstOrFail();

        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('Google Demo', $user->name);
        $this->assertSame('google.user@example.com', $user->email);
        $this->assertSame('https://example.com/avatar.png', $user->google_avatar);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_google_callback_links_an_existing_email_password_account(): void
    {
        $user = User::query()->create([
            'name' => 'Existing Demo',
            'email' => 'existing@example.com',
            'password' => Hash::make('password123'),
        ]);

        Socialite::fake('google', $this->fakeGoogleUser(
            id: 'google-456',
            email: 'existing@example.com',
            name: 'Google Existing',
            avatar: 'https://example.com/existing.png',
        ));

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/dashboard');

        $user->refresh();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-456', $user->google_id);
        $this->assertSame('https://example.com/existing.png', $user->google_avatar);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_google_callback_requires_an_email_address(): void
    {
        Socialite::fake('google', $this->fakeGoogleUser(email: null));

        $response = $this->from('/login')->get('/auth/google/callback');

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    private function fakeGoogleUser(
        string $id = 'google-123',
        ?string $email = 'google.user@example.com',
        string $name = 'Google Demo',
        ?string $avatar = 'https://example.com/avatar.png',
    ): SocialiteUser {
        return (new SocialiteUser)->setRaw([
            'sub' => $id,
            'email' => $email,
            'name' => $name,
            'picture' => $avatar,
        ])->map([
            'id' => $id,
            'nickname' => null,
            'name' => $name,
            'email' => $email,
            'avatar' => $avatar,
        ]);
    }
}
