# Clase práctica 5 — Gestión de tareas

Aplicación de gestión de proyectos y tareas para la clase práctica 5.

## Sitio web

La página principal publicada por GitHub Pages usa almacenamiento local del navegador. Los datos de la demostración se guardan en cada navegador y no se comparten entre dispositivos ni requieren MySQL.

Cuenta de demostración:

- Correo: `admin@local.test`
- Contraseña: `password`

## PHP y MySQL en Laragon

El código PHP se encuentra en `public/`, la configuración en `config.php` y el esquema en `database/pwapractica5.sql`.

1. Coloca el proyecto dentro de `C:\laragon\www\pwapractica5`.
2. Inicia Apache y MySQL en Laragon.
3. Importa `database/pwapractica5.sql` desde phpMyAdmin.
4. Usa los datos locales de Laragon en `config.php`.
5. Abre `http://localhost/pwapractica5/public/index.php`.

GitHub Pages publica la versión HTML, CSS y JavaScript. No ejecuta PHP ni conecta directamente con MySQL.