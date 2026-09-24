-- Para una base que ya tiene datos NO corras este archivo entero (los ALTER
-- fallan si la columna ya existe y phpMyAdmin se corta ahí). Usá migrate.php:
-- compara la base con este archivo y aplica solo lo que falta.
-- Este archivo sirve para crear una base nueva y como referencia del esquema.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Comerciales: quienes traen cuentas y cobran una parte. La wallet es la
-- dirección desde la que se les hacen los pagos.
CREATE TABLE IF NOT EXISTS commercials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  wallet_address CHAR(42) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  address CHAR(42) NULL UNIQUE, -- obsoleta: las direcciones viven en account_addresses
  notes TEXT NULL,
  code VARCHAR(64) NULL,
  start_date DATE NULL,
  next_report_date DATE NULL,
  last_report_date DATE NULL,
  last_report_url VARCHAR(500) NULL,
  high_water_mark DECIMAL(20,2) NULL,
  performance_fee DECIMAL(5,2) NULL, -- porcentaje, 0 a 100
  commercial_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (commercial_id) REFERENCES commercials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una cuenta puede tener varias direcciones de blockchain (EVM: Ethereum / BSC).
-- Una dirección solo puede pertenecer a una cuenta.
CREATE TABLE IF NOT EXISTS account_addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  address CHAR(42) NOT NULL UNIQUE,
  label VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account (account_id),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notas de cada cuenta: puede tener varias, la más reciente se muestra primero.
-- Reemplaza a accounts.notes (la migración copia la nota vieja acá).
CREATE TABLE IF NOT EXISTS account_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_account (account_id),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Qué usuarios tienen acceso a qué cuentas (relación muchos a muchos).
-- Los admins ven todas las cuentas sin necesidad de estar acá.
CREATE TABLE IF NOT EXISTS account_user (
  account_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (account_id, user_id),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens que el admin decidió ocultar (spam que el filtro automático no
-- detecta). Es global: aplica a todas las cuentas. Se identifica por
-- red + contrato.
CREATE TABLE IF NOT EXISTS ignored_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain VARCHAR(8) NOT NULL,
  contract CHAR(42) NOT NULL,
  symbol VARCHAR(255) NULL,
  name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_chain_contract (chain, contract)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Última consulta a las APIs de blockchain por dirección y red (saldo, tokens
-- y movimientos en JSON), para no consultarlas en cada visita. Solo un admin
-- la actualiza. updated_at = última actualización exitosa; error = mensaje de
-- la última que falló (se limpia al tener éxito, los datos viejos se conservan).
CREATE TABLE IF NOT EXISTS chain_snapshots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  address_id INT UNSIGNED NOT NULL,
  chain VARCHAR(8) NOT NULL,
  data MEDIUMTEXT NULL,
  error VARCHAR(500) NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uniq_address_chain (address_id, chain),
  FOREIGN KEY (address_id) REFERENCES account_addresses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ajustes y notas generales de la app (clave -> valor). Hoy solo guarda las
-- notas de la página Configuración (name = 'config_notes').
CREATE TABLE IF NOT EXISTS app_settings (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pagos a comerciales. Cada pago sale del cobro de una cuenta (account_id).
-- amount en USD; tx_hash es opcional, para poder rastrear el pago on-chain.
CREATE TABLE IF NOT EXISTS commercial_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  commercial_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  amount DECIMAL(20,2) NOT NULL,
  paid_at DATE NOT NULL,
  tx_hash VARCHAR(80) NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_commercial (commercial_id),
  FOREIGN KEY (commercial_id) REFERENCES commercials(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alta manual de la primera cuenta para poder probar el login.
-- Reemplazá el email y ejecutá esta linea aparte.
-- INSERT INTO users (email) VALUES ('tu-email@ejemplo.com');

-- Migración: si la tabla `users` ya existía sin las columnas name/is_admin
-- (creada antes de este cambio), corré esto una sola vez. Si las columnas
-- ya existen, MySQL va a tirar error "Duplicate column" y no pasa nada,
-- simplemente lo ignorás.
ALTER TABLE users ADD COLUMN name VARCHAR(255) NULL AFTER email;
ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

UPDATE users SET is_admin = 1 WHERE email = 'g.suarezduek@gmail.com';

-- Migración: agregar columna ip a login_codes si la tabla ya existía
-- (para el rate limiting). Mismo criterio: si ya existe, tira error y
-- lo ignorás.
ALTER TABLE login_codes ADD COLUMN ip VARCHAR(45) NULL AFTER attempts;
ALTER TABLE login_codes ADD INDEX idx_ip (ip);

-- Migración: agregar columna notes a accounts si la tabla ya existía.
ALTER TABLE accounts ADD COLUMN notes TEXT NULL AFTER address;

-- Migración: pasar a direcciones múltiples por cuenta. Correr una sola vez,
-- después de crear la tabla account_addresses (el CREATE de arriba).
-- 1) Copia la dirección que ya tenía cada cuenta a la tabla nueva.
INSERT IGNORE INTO account_addresses (account_id, address)
  SELECT id, address FROM accounts WHERE address IS NOT NULL;
-- 2) La columna vieja queda opcional (las cuentas nuevas ya no la usan).
ALTER TABLE accounts MODIFY address CHAR(42) NULL;

-- Migración: datos de seguimiento de la cuenta (inicio, informes, marca de
-- agua / high-water mark). Si ya existen las columnas, tira error y lo ignorás.
ALTER TABLE accounts ADD COLUMN start_date DATE NULL AFTER notes;
ALTER TABLE accounts ADD COLUMN next_report_date DATE NULL AFTER start_date;
ALTER TABLE accounts ADD COLUMN last_report_date DATE NULL AFTER next_report_date;
ALTER TABLE accounts ADD COLUMN last_report_url VARCHAR(500) NULL AFTER last_report_date;
ALTER TABLE accounts ADD COLUMN high_water_mark DECIMAL(20,2) NULL AFTER last_report_url;

-- Migración: código de la cuenta (alfanumérico, puede llevar signos).
ALTER TABLE accounts ADD COLUMN code VARCHAR(64) NULL AFTER notes;

-- Migración: fee de performance y comercial en cada cuenta. Correr después de
-- crear las tablas commercials y commercial_payments (los CREATE de arriba).
ALTER TABLE accounts ADD COLUMN performance_fee DECIMAL(5,2) NULL AFTER high_water_mark;
ALTER TABLE accounts ADD COLUMN commercial_id INT UNSIGNED NULL AFTER performance_fee;
ALTER TABLE accounts ADD FOREIGN KEY (commercial_id) REFERENCES commercials(id) ON DELETE SET NULL;

-- Migración: notas múltiples por cuenta. Correr después de crear la tabla
-- account_notes (el CREATE de arriba). Copia la nota vieja de cada cuenta que
-- todavía no tiene notas nuevas. accounts.notes queda obsoleta.
INSERT INTO account_notes (account_id, body, created_at)
  SELECT a.id, a.notes, a.created_at FROM accounts a
  WHERE a.notes IS NOT NULL AND a.notes <> ''
    AND NOT EXISTS (SELECT 1 FROM account_notes n WHERE n.account_id = a.id);
