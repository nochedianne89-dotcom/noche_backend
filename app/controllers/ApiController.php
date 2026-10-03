<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
    }

    public function register()
{
    $this->api->require_method('POST');
    $data = $this->api->body();

    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($username) || empty($password)) {
        $this->api->respond_error('Username and password are required', 422);
        return;
    }

    $this->call->model('AccountModel');
    $existing = $this->AccountModel->getByUsername($username);

    if ($existing) {
        $this->api->respond_error('Username already taken', 409);
        return;
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $this->AccountModel->insert([
        'username' => $username,
        'password' => $hashed,
    ]);

    $this->api->respond(['message' => 'Registered successfully'], 201);
}

    public function login()
    {
        $this->api->require_method('POST');
        $data = $this->api->body();

        $this->call->model('AccountModel');
        $account = $this->AccountModel->getByUsername($data['username'] ?? '');

        if (!$account || !password_verify($data['password'] ?? '', $account['password'])) {
            $this->api->respond_error('Invalid username or password', 401);
            return;
        }

        $tokens = $this->api->issue_tokens(['id' => $account['id']]);
        $this->api->respond(['message' => 'Login successful', 'tokens' => $tokens]);
    }

    public function refresh()
    {
        $this->api->require_method('POST');
        $data = $this->api->body();
        $this->api->refresh_access_token($data['refresh_token'] ?? '');
    }

    public function logout()
    {
        $this->api->require_method('POST');
        $data = $this->api->body();
        $this->api->revoke_refresh_token($data['refresh_token'] ?? '');
        $this->api->respond(['message' => 'Logged out successfully']);
    }

    public function products()
    {
        $this->api->require_jwt();
        $this->call->model('ProductModel');

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $products = $this->ProductModel->getAll();
            $this->api->respond(['data' => $products]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = $this->api->body();
            $this->ProductModel->insert([
                'product_name' => $data['product_name'] ?? '',
                'description'  => $data['description'] ?? '',
                'price'        => $data['price'] ?? 0,
                'quantity'     => $data['quantity'] ?? 0,
            ]);
            $this->api->respond(['message' => 'Product created'], 201);
            return;
        }

        $this->api->respond_error('Method Not Allowed', 405);
    }

    public function product($id)
    {
        $this->api->require_jwt();
        $this->call->model('ProductModel');

        if (in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'])) {
            $data = $this->api->body();
            $this->ProductModel->update($id, [
                'product_name' => $data['product_name'] ?? '',
                'description'  => $data['description'] ?? '',
                'price'        => $data['price'] ?? 0,
                'quantity'     => $data['quantity'] ?? 0,
            ]);
            $this->api->respond(['message' => 'Product updated']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->ProductModel->delete($id);
            $this->api->respond(['message' => 'Product deleted']);
            return;
        }

        $this->api->respond_error('Method Not Allowed', 405);
    }
}