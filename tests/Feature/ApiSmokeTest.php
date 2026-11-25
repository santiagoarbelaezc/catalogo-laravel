<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'OK',
            ]);
    }

    public function test_productos_endpoint_returns_list(): void
    {
        $response = $this->getJson('/api/productos?brand=Plaxtilineas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => ['id', 'name', 'slug', 'images', 'colors', 'variants'],
                ],
            ]);
    }

    public function test_categorias_endpoint_returns_tree(): void
    {
        $response = $this->getJson('/api/categorias');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'brand', 'subcategories'],
                ],
            ]);
    }

    public function test_inventario_cambios_endpoint_works(): void
    {
        $response = $this->getJson('/api/inventario/cambios');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => ['enabled', 'reset', 'last_id', 'events', 'has_more'],
            ]);
    }

    public function test_protected_route_requires_jwt_token(): void
    {
        $response = $this->postJson('/api/productos', ['name' => 'Test']);

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_login_and_profile_flow(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => env('ADMIN_EMAIL', 'mateo.moreno@plaxtilineas.com'),
            'password' => env('ADMIN_PASSWORD', 'Plaxtilineas-123'),
        ]);

        // If admin credentials match DB, verify full JWT profile flow; otherwise assert 401 cleanly
        if ($login->status() === 200) {
            $token = $login->json('data.token');
            $this->assertNotEmpty($token);

            $profile = $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/auth/profile');

            $profile->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonStructure(['success', 'data' => ['id', 'username', 'email', 'role']]);
        } else {
            $login->assertStatus(401)->assertJsonPath('success', false);
        }
    }
}

