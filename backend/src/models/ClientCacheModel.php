<?php

declare(strict_types=1);

namespace App\models;

final class ClientCacheModel extends BaseModel
{
    public function upsertClient(array $client): int
    {
        // Check if exists first for MySQL (Dual-step upsert compatible with simple WPDB wrapper)
        $fetch = $this->db->prepare('SELECT id FROM clients_cache WHERE whmcs_client_id = :id LIMIT 1');
        $fetch->execute(['id' => $client['whmcs_client_id']]);
        $existingId = $fetch->fetchColumn();

        if ($existingId) {
            $stmt = $this->db->prepare('UPDATE clients_cache SET 
                email = :email, 
                first_name = :first_name, 
                last_name = :last_name, 
                updated_at = :updated_at 
                WHERE whmcs_client_id = :whmcs_client_id');
            $stmt->execute([
                'email' => strtolower($client['email']),
                'first_name' => $client['first_name'] ?? null,
                'last_name' => $client['last_name'] ?? null,
                'updated_at' => date('Y-m-d H:i:s'),
                'whmcs_client_id' => $client['whmcs_client_id'],
            ]);
            return (int) $existingId;
        }

        $stmt = $this->db->prepare('INSERT INTO clients_cache (
            whmcs_client_id, email, first_name, last_name, created_at, updated_at
        ) VALUES (
            :whmcs_client_id, :email, :first_name, :last_name, :created_at, :updated_at
        )');
        $stmt->execute([
            'whmcs_client_id' => $client['whmcs_client_id'],
            'email' => strtolower($client['email']),
            'first_name' => $client['first_name'] ?? null,
            'last_name' => $client['last_name'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function replaceDomains(int $clientId, array $domains): void
    {
        $delete = $this->db->prepare('DELETE FROM client_domains WHERE client_id = :client_id');
        $delete->execute(['client_id' => $clientId]);

        $insert = $this->db->prepare('INSERT INTO client_domains (client_id, domain, created_at) VALUES (:client_id, :domain, :created_at)');
        foreach ($domains as $domain) {
            $insert->execute([
                'client_id' => $clientId,
                'domain' => strtolower($domain),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
