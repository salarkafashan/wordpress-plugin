PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS clients_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    whmcs_client_id INTEGER NOT NULL UNIQUE,
    email TEXT NOT NULL,
    first_name TEXT,
    last_name TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS client_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    domain TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (client_id) REFERENCES clients_cache(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS client_jira_maps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    whmcs_client_id INTEGER,
    jira_project_id TEXT,
    jira_project_key TEXT NOT NULL,
    jira_project_name TEXT,
    jira_board_id TEXT,
    jira_space_name TEXT,
    website_url TEXT,
    client_company_name TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    mapping_source TEXT NOT NULL DEFAULT 'manual',
    notes TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS support_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    client_id INTEGER,
    client_whmcs_id INTEGER,
    client_name TEXT,
    client_company TEXT,
    submitted_email TEXT NOT NULL,
    verified_email TEXT,
    website_domain TEXT NOT NULL,
    status TEXT NOT NULL,
    duplicate_hash TEXT NOT NULL,
    duplicate_override INTEGER NOT NULL DEFAULT 0,
    metadata_json TEXT NOT NULL,
    confirmation_token_hash TEXT NOT NULL UNIQUE,
    confirmation_sent_to TEXT NOT NULL,
    confirmation_expires_at TEXT NOT NULL,
    confirmed_at TEXT,
    jira_issue_key TEXT,
    jira_status TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS support_issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    issue_type TEXT NOT NULL,
    urgency_level TEXT NOT NULL,
    page_url TEXT NOT NULL,
    description TEXT NOT NULL,
    current_content TEXT,
    new_content TEXT,
    suggested_issue_type TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES support_requests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS issue_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    issue_id INTEGER,
    original_name TEXT NOT NULL,
    stored_name TEXT,
    mime_type TEXT NOT NULL,
    extension TEXT NOT NULL,
    category TEXT NOT NULL,
    temp_path TEXT,
    file_path TEXT,
    file_size_original INTEGER NOT NULL DEFAULT 0,
    file_size_optimized INTEGER,
    optimization_status TEXT NOT NULL DEFAULT "uploaded_temp",
    jira_attachment_status TEXT NOT NULL DEFAULT "pending",
    sha256_hash TEXT NOT NULL,
    is_screenshot INTEGER NOT NULL DEFAULT 0,
    retention_delete_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES support_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (issue_id) REFERENCES support_issues(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ticket_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id INTEGER NOT NULL,
    job_type TEXT NOT NULL,
    payload_json TEXT,
    status TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    last_error TEXT,
    next_run_at TEXT NOT NULL,
    locked_at TEXT,
    lock_token TEXT,
    processed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES support_requests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    limiter_key TEXT NOT NULL,
    created_at_unix INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_support_requests_status ON support_requests(status);
CREATE INDEX IF NOT EXISTS idx_support_requests_email ON support_requests(submitted_email);
CREATE INDEX IF NOT EXISTS idx_support_requests_duplicate_hash ON support_requests(duplicate_hash);
CREATE INDEX IF NOT EXISTS idx_support_issues_request_id ON support_issues(request_id);
CREATE INDEX IF NOT EXISTS idx_issue_attachments_request_id ON issue_attachments(request_id);
CREATE INDEX IF NOT EXISTS idx_issue_attachments_issue_id ON issue_attachments(issue_id);
CREATE INDEX IF NOT EXISTS idx_issue_attachments_opt_status ON issue_attachments(optimization_status);
CREATE INDEX IF NOT EXISTS idx_issue_attachments_jira_status ON issue_attachments(jira_attachment_status);
CREATE INDEX IF NOT EXISTS idx_issue_attachments_retention ON issue_attachments(retention_delete_at);
CREATE INDEX IF NOT EXISTS idx_issue_attachments_hash ON issue_attachments(sha256_hash);
CREATE INDEX IF NOT EXISTS idx_ticket_queue_status ON ticket_queue(status);
CREATE INDEX IF NOT EXISTS idx_ticket_queue_request_id ON ticket_queue(request_id);
CREATE INDEX IF NOT EXISTS idx_ticket_queue_next_run ON ticket_queue(next_run_at);
CREATE INDEX IF NOT EXISTS idx_client_domains_domain ON client_domains(domain);
CREATE INDEX IF NOT EXISTS idx_client_jira_maps_project_key ON client_jira_maps(jira_project_key);
CREATE INDEX IF NOT EXISTS idx_client_jira_maps_website_url ON client_jira_maps(website_url);
CREATE INDEX IF NOT EXISTS idx_client_jira_maps_is_active ON client_jira_maps(is_active);
CREATE INDEX IF NOT EXISTS idx_client_jira_maps_whmcs_client_id ON client_jira_maps(whmcs_client_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_client_jira_maps_project_id_unique ON client_jira_maps(jira_project_id);
CREATE INDEX IF NOT EXISTS idx_rate_limits_key_time ON rate_limits(limiter_key, created_at_unix);
