<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    // ==================== REGISTRATION TESTS ====================

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'balance',
                    'locked_balance',
                    'total_balance',
                    'is_active',
                    'can_trade',
                    'created_at',
                ],
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
            'is_active' => true,
        ]);
    }

    public function test_user_starts_with_zero_balance_after_registration(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertEquals('0.00000000', $user->balance);
        $this->assertEquals('0.00000000', $user->locked_balance);
    }

    public function test_registration_fails_with_weak_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_fails_with_password_mismatch(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_returns_authentication_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('token'));

        // Token should be usable
        $token = $response->json('token');
        $profileResponse = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/profile');
        $profileResponse->assertStatus(200);
    }

    // ==================== LOGIN TESTS ====================

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user',
                'token',
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('SecurePass123!'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('SecurePass123!'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_for_suspended_user(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
            'suspended_at' => now(),
            'suspension_reason' => 'Suspicious activity',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_revokes_previous_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('SecurePass123!'),
            'is_active' => true,
        ]);

        // Create an initial token
        $oldToken = $user->createToken('old-token')->plainTextToken;

        // Login to get a new token
        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(200);
        $newToken = $response->json('token');

        // Old token should no longer work
        $oldTokenResponse = $this->withHeaders(['Authorization' => "Bearer {$oldToken}"])
            ->getJson('/api/profile');
        $oldTokenResponse->assertStatus(401);

        // New token should work
        $newTokenResponse = $this->withHeaders(['Authorization' => "Bearer {$newToken}"])
            ->getJson('/api/profile');
        $newTokenResponse->assertStatus(200);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ==================== LOGOUT TESTS ====================

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('auth-token')->plainTextToken;

        // Verify token exists before logout
        $this->assertEquals(1, $user->tokens()->count());

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully']);

        // Token should be deleted from database
        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_logout_fails_without_token(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertStatus(401);
    }

    // ==================== PROFILE TESTS ====================

    public function test_user_can_get_profile(): void
    {
        $user = User::factory()->create([
            'balance' => '1000.00000000',
            'locked_balance' => '100.00000000',
            'is_active' => true,
        ]);
        $token = $user->createToken('auth-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'balance',
                    'locked_balance',
                    'total_balance',
                    'is_active',
                    'can_trade',
                    'assets',
                    'created_at',
                ],
            ]);

        $this->assertEquals('1000.00000000', $response->json('user.balance'));
        $this->assertEquals('100.00000000', $response->json('user.locked_balance'));
        $this->assertEquals('1100.00000000', $response->json('user.total_balance'));
    }

    public function test_active_user_can_trade(): void
    {
        $activeUser = User::factory()->create(['is_active' => true]);
        $activeToken = $activeUser->createToken('auth-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$activeToken}"])
            ->getJson('/api/profile');

        $this->assertTrue($response->json('user.can_trade'));
    }

    public function test_suspended_user_cannot_trade(): void
    {
        $suspendedUser = User::factory()->create([
            'is_active' => true,
            'suspended_at' => now(),
        ]);

        // Verify the model correctly identifies as suspended
        $this->assertTrue($suspendedUser->isSuspended());
        $this->assertFalse($suspendedUser->canTrade());

        $suspendedToken = $suspendedUser->createToken('auth-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$suspendedToken}"])
            ->getJson('/api/profile');

        $this->assertFalse($response->json('user.can_trade'));
    }

    public function test_profile_requires_authentication(): void
    {
        $response = $this->getJson('/api/profile');

        $response->assertStatus(401);
    }
}
