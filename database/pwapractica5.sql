CREATE DATABASE IF NOT EXISTS pwapractica5 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pwapractica5;

CREATE TABLE IF NOT EXISTS roles (
  rol_id INT AUTO_INCREMENT PRIMARY KEY,
  rol_nombre VARCHAR(30) NOT NULL UNIQUE
);

INSERT IGNORE INTO roles (rol_nombre) VALUES ('Administrador'), ('Gerente de proyecto'), ('Miembro del equipo');

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  contrasena VARCHAR(255) NOT NULL,
  rol_id INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rol_id) REFERENCES roles(rol_id)
);

CREATE TABLE IF NOT EXISTS proyectos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(300),
  gerente_id INT NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (gerente_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS proyecto_usuarios (
  proyecto_id INT NOT NULL,
  usuario_id INT NOT NULL,
  PRIMARY KEY (proyecto_id, usuario_id),
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tareas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proyecto_id INT NOT NULL,
  titulo VARCHAR(100) NOT NULL,
  descripcion VARCHAR(500),
  estado ENUM('Pendiente', 'En proceso', 'Completado') NOT NULL DEFAULT 'Pendiente',
  usuario_id INT NOT NULL,
  creador_id INT NOT NULL,
  fecha_limite DATE NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY (creador_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

INSERT IGNORE INTO usuarios (nombre, email, contrasena, rol_id)
SELECT 'Administrador', 'admin@local.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', rol_id
FROM roles WHERE rol_nombre = 'Administrador';
