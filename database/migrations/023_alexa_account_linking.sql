-- Alexa OAuth-2 account linking. Only hashes of bearer credentials are stored.
CREATE TABLE IF NOT EXISTS alexa_links (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  access_token_hash VARCHAR(64) NOT NULL,
  refresh_token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  refresh_expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alexa_links_user (user_id),
  UNIQUE KEY uniq_alexa_access_hash (access_token_hash),
  UNIQUE KEY uniq_alexa_refresh_hash (refresh_token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alexa_authorization_codes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  code_hash VARCHAR(64) NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  redirect_uri TEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alexa_code_hash (code_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
