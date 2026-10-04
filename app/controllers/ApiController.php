<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiController extends Controller
{
    private $secret;

   public function __construct()
{
    parent::__construct();
    $this->call->database();   // <-- idagdag ito

    $this->secret = getenv('JWT_SECRET') ?: 'dev-secret-change-me';

    // CORS: payagan ang listahan ng origins sa FRONTEND_URL (comma-separated)
    $allowed = array_filter(array_map('trim', explode(',', getenv('FRONTEND_URL') ?: 'http://localhost:5173')));
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

    /* ---------- helpers ---------- */

    private function input()
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    private function respond($data, $code = 200)
    {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }

    private function b64($s)
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function jwt_encode($payload)
    {
        $h   = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p   = $this->b64(json_encode($payload));
        $sig = $this->b64(hash_hmac('sha256', "$h.$p", $this->secret, true));
        return "$h.$p.$sig";
    }

    private function jwt_decode($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$h, $p, $sig] = $parts;
        $expected = $this->b64(hash_hmac('sha256', "$h.$p", $this->secret, true));
        if (!hash_equals($expected, $sig)) return null;
        $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
        return $payload;
    }

    private function require_auth()
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$header && function_exists('getallheaders')) {
            $all    = array_change_key_case(getallheaders(), CASE_LOWER);
            $header = $all['authorization'] ?? '';
        }
        if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) {
            $this->respond(['error' => 'Missing token'], 401);
        }
        $payload = $this->jwt_decode($m[1]);
        if (!$payload) {
            $this->respond(['error' => 'Invalid or expired token'], 401);
        }
        return $payload;
    }

private function issue_tokens($user)
{
    $access = $this->jwt_encode([
        'sub'      => $user['id'],
        'username' => $user['username'],
        'iat'      => time(),
        'exp'      => time() + 3600,
    ]);
    $refresh = bin2hex(random_bytes(32));
    $this->db->table('refresh_tokens')->insert([
        'user_id'    => $user['id'],
        'token'      => hash('sha256', $refresh),
        'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
        'jti'        => bin2hex(random_bytes(16)),
    ]);
    return [
        'access_token'  => $access,
        'token'         => $access,
        'refresh_token' => $refresh,
    ];
}
    private function product_payload($in)
    {
        $name  = trim($in['product_name'] ?? '');
        $price = $in['price'] ?? null;
        $qty   = $in['quantity'] ?? null;
        if ($name === '' || strlen($name) > 100 || !is_numeric($price) || $price < 0
            || !is_numeric($qty) || $qty < 0) {
            $this->respond(['error' => 'Invalid product data'], 422);
        }
        return [
            'product_name' => $name,
            'description'  => $in['description'] ?? '',
            'price'        => round((float) $price, 2),
            'quantity'     => (int) $qty,
        ];
    }

    /* ---------- auth ---------- */

   public function register()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->respond(['error' => 'Method not allowed'], 405);

    $in       = $this->input();
    $username = trim($in['username'] ?? '');
    $email    = trim($in['email'] ?? '');
    $password = $in['password'] ?? '';

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $this->respond(['error' => 'Username, a valid email, and a password of at least 6 characters are required'], 422);
    }
    if ($this->db->table('users')->where('username', $username)->get()) {
        $this->respond(['error' => 'This username is already taken. Please choose a different username.'], 409);
    }
    if ($this->db->table('users')->where('email', $email)->get()) {
        $this->respond(['error' => 'This email is already registered. Please use a different email.'], 409);
    }

    $this->db->table('users')->insert([
        'username' => $username,
        'email'    => $email,
        'password' => password_hash($password, PASSWORD_BCRYPT),
    ]);
    $this->respond(['message' => 'Account created successfully. You can now log in.'], 201);
}

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->respond(['error' => 'Method not allowed'], 405);

        $in   = $this->input();
        $user = $this->db->table('users')->where('username', trim($in['username'] ?? ''))->get();

        if (!$user || !password_verify($in['password'] ?? '', $user['password'])) {
            $this->respond(['error' => 'Invalid username or password'], 401);
        }
        if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
            $this->respond(['error' => 'Account is disabled'], 403);
        }
        $this->respond($this->issue_tokens($user));
    }

   public function refresh()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $this->respond(['error' => 'Method not allowed'], 405);

    $in   = $this->input();
    $hash = hash('sha256', $in['refresh_token'] ?? '');
    $row  = $this->db->table('refresh_tokens')->where('token', $hash)->get();

    if (!$row || strtotime($row['expires_at']) < time()) {
        $this->respond(['error' => 'Invalid refresh token'], 401);
    }
    $user = $this->db->table('users')->where('id', $row['user_id'])->get();
    if (!$user) $this->respond(['error' => 'User not found'], 401);

    $this->db->table('refresh_tokens')->where('token', $hash)->delete();
    $this->respond($this->issue_tokens($user));
}
public function logout()
{
    $in = $this->input();
    if (!empty($in['refresh_token'])) {
        $this->db->table('refresh_tokens')
            ->where('token', hash('sha256', $in['refresh_token']))->delete();
    }
    $this->respond(['message' => 'Logged out']);
}

    /* ---------- products (protected) ---------- */

    public function products()
    {
        $this->require_auth();

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond(['data' => $this->db->table('products')->order_by('id', 'DESC')->get_all()]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->db->table('products')->insert($this->product_payload($this->input()));
            $this->respond(['message' => 'Product added'], 201);
        }
        $this->respond(['error' => 'Method not allowed'], 405);
    }

    public function product($id)
    {
        $this->require_auth();
        $id = (int) $id;

        $existing = $this->db->table('products')->where('id', $id)->get();
        if (!$existing) $this->respond(['error' => 'Product not found'], 404);

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                $this->respond(['data' => $existing]);
            case 'PUT':
            case 'PATCH':
                $this->db->table('products')->where('id', $id)
                    ->update($this->product_payload($this->input()));
                $this->respond(['message' => 'Product updated']);
            case 'DELETE':
                $this->db->table('products')->where('id', $id)->delete();
                $this->respond(['message' => 'Product deleted']);
        }
        $this->respond(['error' => 'Method not allowed'], 405);
    }
}