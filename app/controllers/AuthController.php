<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends Controller
{
    public function register()
    {
        $username = $this->io->post('username');
        $password = $this->io->post('password');

        // Hash password bago i-save
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $this->db->table('users')->insert([
            'username' => $username,
            'password' => $hashedPassword
        ]);

        echo json_encode(['message' => 'User registered successfully']);
    }

    public function login()
    {
        $username = $this->io->post('username');
        $password = $this->io->post('password');

        $user = $this->db->table('users')->where('username', $username)->get();

        if ($user && password_verify($password, $user['password'])) {
            // Generate JWT token
            $jwtSecret = getenv('JWT_SECRET');
            $payload = [
                'sub' => $user['id'],
                'username' => $user['username'],
                'iat' => time(),
                'exp' => time() + 3600
            ];

            $token = \Firebase\JWT\JWT::encode($payload, $jwtSecret, 'HS256');

            echo json_encode(['token' => $token]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid username or password']);
        }
    }
}
