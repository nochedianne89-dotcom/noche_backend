<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AccountModel extends Model
{
    protected $table = 'accounts';

    public function getByUsername($username)
    {
        return $this->db->table($this->table)->where('username', $username)->get();
    }

    public function insert($data)
    {
        return $this->db->table($this->table)->insert($data);
    }
}