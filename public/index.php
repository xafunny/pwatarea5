<?php
declare(strict_types=1);
session_start();
require_once dirname(__DIR__) . '/config.php';

$pdo = null;
$dbError = null;
try {
    $pdo = db();
} catch (Throwable $error) {
    $dbError = 'No se pudo conectar con MySQL. Revisa que Laragon esté iniciado y configura config.php.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'login') {
            $query = $pdo->prepare('SELECT u.id, u.nombre, u.email, u.contrasena, r.rol_nombre FROM usuarios u JOIN roles r ON r.rol_id = u.rol_id WHERE u.email = ?');
            $query->execute([trim($_POST['email'] ?? '')]);
            $account = $query->fetch();
            if ($account && password_verify($_POST['password'] ?? '', $account['contrasena'])) {
                session_regenerate_id(true);
                unset($account['contrasena']);
                $_SESSION['user'] = $account;
                redirect('index.php');
            }
            flash('Correo o contraseña incorrectos.', 'error');
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirect('index.php');
        } elseif ($action === 'create_user') {
            require_role('Administrador');
            $role = (int)($_POST['rol_id'] ?? 0);
            $query = $pdo->prepare('INSERT INTO usuarios (nombre, email, contrasena, rol_id) VALUES (?, ?, ?, ?)');
            $query->execute([trim($_POST['nombre'] ?? ''), trim($_POST['email'] ?? ''), password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT), $role]);
            flash('Usuario creado correctamente.');
        } elseif ($action === 'delete_user') {
            require_role('Administrador');
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)user()['id']) {
                throw new RuntimeException('No puedes eliminar tu propia cuenta.');
            }
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
            flash('Usuario eliminado.');
        } elseif ($action === 'create_project') {
            require_role('Administrador');
            $managerId = (int)($_POST['gerente_id'] ?? 0);
            $query = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.rol_id=u.rol_id WHERE u.id=? AND r.rol_nombre='Gerente de proyecto'");
            $query->execute([$managerId]);
            if (!$query->fetchColumn()) {
                throw new RuntimeException('Selecciona un gerente válido.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO proyectos (nombre, descripcion, gerente_id) VALUES (?, ?, ?)')->execute([trim($_POST['nombre'] ?? ''), trim($_POST['descripcion'] ?? ''), $managerId]);
            $projectId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO proyecto_usuarios (proyecto_id, usuario_id) VALUES (?, ?)')->execute([$projectId, $managerId]);
            $pdo->commit();
            flash('Proyecto creado y asignado al gerente.');
        } elseif ($action === 'add_member') {
            require_role('Gerente de proyecto', 'Administrador');
            $projectId = (int)($_POST['proyecto_id'] ?? 0);
            if (!can_access_project($projectId)) {
                throw new RuntimeException('No tienes acceso a ese proyecto.');
            }
            $memberId = (int)($_POST['usuario_id'] ?? 0);
            $query = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.rol_id=u.rol_id WHERE u.id=? AND r.rol_nombre='Miembro del equipo'");
            $query->execute([$memberId]);
            if (!$query->fetchColumn()) {
                throw new RuntimeException('Selecciona un miembro válido.');
            }
            $pdo->prepare('INSERT IGNORE INTO proyecto_usuarios (proyecto_id, usuario_id) VALUES (?, ?)')->execute([$projectId, $memberId]);
            flash('Miembro agregado al proyecto.');
        } elseif ($action === 'save_task') {
            require_role('Gerente de proyecto', 'Administrador');
            $projectId = (int)($_POST['proyecto_id'] ?? 0);
            if (!can_access_project($projectId)) {
                throw new RuntimeException('No tienes acceso a ese proyecto.');
            }
            $memberId = (int)($_POST['usuario_id'] ?? 0);
            $query = $pdo->prepare('SELECT 1 FROM proyecto_usuarios WHERE proyecto_id=? AND usuario_id=?');
            $query->execute([$projectId, $memberId]);
            if (!$query->fetchColumn()) {
                throw new RuntimeException('El responsable debe pertenecer al proyecto.');
            }
            $taskId = (int)($_POST['id'] ?? 0);
            if ($taskId) {
                $check = $pdo->prepare('SELECT id FROM tareas WHERE id=? AND proyecto_id=?');
                $check->execute([$taskId, $projectId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('La tarea no pertenece al proyecto.');
                }
                $pdo->prepare('UPDATE tareas SET titulo=?, descripcion=?, usuario_id=?, fecha_limite=? WHERE id=?')->execute([trim($_POST['titulo'] ?? ''), trim($_POST['descripcion'] ?? ''), $memberId, $_POST['fecha_limite'] ?: null, $taskId]);
                flash('Tarea actualizada.');
            } else {
                $pdo->prepare('INSERT INTO tareas (proyecto_id, titulo, descripcion, usuario_id, creador_id, fecha_limite) VALUES (?, ?, ?, ?, ?, ?)')->execute([$projectId, trim($_POST['titulo'] ?? ''), trim($_POST['descripcion'] ?? ''), $memberId, user()['id'], $_POST['fecha_limite'] ?: null]);
                flash('Tarea creada.');
            }
        } elseif ($action === 'delete_task') {
            require_role('Gerente de proyecto', 'Administrador');
            $taskId = (int)($_POST['id'] ?? 0);
            $query = $pdo->prepare('SELECT proyecto_id FROM tareas WHERE id=?');
            $query->execute([$taskId]);
            $projectId = (int)$query->fetchColumn();
            if (!$projectId || !can_access_project($projectId)) {
                throw new RuntimeException('No puedes eliminar esta tarea.');
            }
            $pdo->prepare('DELETE FROM tareas WHERE id=?')->execute([$taskId]);
            flash('Tarea eliminada.');
        } elseif ($action === 'update_status') {
            require_login();
            $taskId = (int)($_POST['id'] ?? 0);
            $state = $_POST['estado'] ?? '';
            if (!in_array($state, ['Pendiente', 'En proceso', 'Completado'], true)) {
                throw new RuntimeException('Estado no válido.');
            }
            $query = $pdo->prepare("SELECT t.id FROM tareas t JOIN roles r ON r.rol_id=(SELECT rol_id FROM usuarios WHERE id=?) WHERE t.id=? AND (t.usuario_id=? OR r.rol_nombre IN ('Administrador','Gerente de proyecto') AND EXISTS (SELECT 1 FROM proyecto_usuarios pu WHERE pu.proyecto_id=t.proyecto_id AND pu.usuario_id=?))");
            $query->execute([user()['id'], $taskId, user()['id'], user()['id']]);
            if (!$query->fetch()) {
                throw new RuntimeException('No puedes actualizar esta tarea.');
            }
            $pdo->prepare('UPDATE tareas SET estado=? WHERE id=?')->execute([$state, $taskId]);
            flash('Estado actualizado.');
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($error instanceof PDOException ? 'No se pudo completar la operación. Verifica los datos y que el correo no esté repetido.' : $error->getMessage(), 'error');
    }
    redirect('index.php');
}

$current = user();
$role = $current['rol_nombre'] ?? '';
$projects = [];
$tasks = [];
$users = [];
$roles = [];
if ($pdo && $current) {
    if ($role === 'Administrador') {
        $projects = $pdo->query('SELECT p.*, u.nombre AS gerente FROM proyectos p JOIN usuarios u ON u.id=p.gerente_id ORDER BY p.id DESC')->fetchAll();
        $users = $pdo->query('SELECT u.id,u.nombre,u.email,r.rol_nombre,u.rol_id FROM usuarios u JOIN roles r ON r.rol_id=u.rol_id ORDER BY u.id DESC')->fetchAll();
        $roles = $pdo->query('SELECT rol_id, rol_nombre FROM roles ORDER BY rol_id')->fetchAll();
    } else {
        $query = $pdo->prepare('SELECT p.*, u.nombre AS gerente FROM proyectos p JOIN proyecto_usuarios pu ON pu.proyecto_id=p.id JOIN usuarios u ON u.id=p.gerente_id WHERE pu.usuario_id=? ORDER BY p.id DESC');
        $query->execute([$current['id']]);
        $projects = $query->fetchAll();
    }
    if ($role === 'Administrador') {
        $tasks = $pdo->query('SELECT t.*, p.nombre AS proyecto, u.nombre AS responsable FROM tareas t JOIN proyectos p ON p.id=t.proyecto_id JOIN usuarios u ON u.id=t.usuario_id ORDER BY t.id DESC')->fetchAll();
    } elseif ($role === 'Gerente de proyecto') {
        $query = $pdo->prepare('SELECT t.*, p.nombre AS proyecto, u.nombre AS responsable FROM tareas t JOIN proyectos p ON p.id=t.proyecto_id JOIN proyecto_usuarios pu ON pu.proyecto_id=p.id JOIN usuarios u ON u.id=t.usuario_id WHERE pu.usuario_id=? ORDER BY t.id DESC');
        $query->execute([$current['id']]);
        $tasks = $query->fetchAll();
    } else {
        $query = $pdo->prepare('SELECT t.*, p.nombre AS proyecto, u.nombre AS responsable FROM tareas t JOIN proyectos p ON p.id=t.proyecto_id JOIN usuarios u ON u.id=t.usuario_id WHERE t.usuario_id=? ORDER BY t.id DESC');
        $query->execute([$current['id']]);
        $tasks = $query->fetchAll();
    }
}
$membersByProject = [];
if ($pdo && $current && in_array($role, ['Administrador', 'Gerente de proyecto'], true)) {
    $memberQuery = $pdo->query("SELECT pu.proyecto_id, u.id, u.nombre FROM proyecto_usuarios pu JOIN usuarios u ON u.id=pu.usuario_id JOIN roles r ON r.rol_id=u.rol_id WHERE r.rol_nombre='Miembro del equipo' ORDER BY u.nombre");
    foreach ($memberQuery->fetchAll() as $member) {
        $membersByProject[$member['proyecto_id']][] = ['id' => (int)$member['id'], 'nombre' => $member['nombre']];
    }
}
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Taskline | Gestión de tareas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/style.css" rel="stylesheet">
</head>
<body>
<header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">T</span> taskline</a><?php if ($current): ?><div class="user-menu"><span class="user-name"><?= e($current['nombre']) ?><small><?= e($role) ?></small></span><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn btn-outline-light btn-sm">Cerrar sesión</button></form></div><?php endif; ?></header>
<?php if ($dbError): ?>
<main class="login-wrap"><section class="login-card"><span class="eyebrow">CONFIGURACIÓN LOCAL</span><h1>Activa el espacio<br>de trabajo.</h1><p><?= e($dbError) ?></p><ol class="setup-list"><li>Inicia Apache y MySQL desde Laragon.</li><li>Importa <code>database/pwapractica5.sql</code> en phpMyAdmin.</li><li>Abre el proyecto desde <code>http://localhost/pwapractica5/</code>.</li></ol></section></main>
<?php elseif (!$current): ?>
<main class="login-wrap"><section class="login-card"><span class="eyebrow">ESPACIO DE TRABAJO · 2025</span><h1>El trabajo<br><span>avanza aquí.</span></h1><p>Inicia sesión para revisar proyectos y tareas del equipo.</p><?php if ($flashMessage): ?><div class="alert alert-<?= $flashMessage['type'] === 'error' ? 'danger' : 'success' ?>"><?= e($flashMessage['message']) ?></div><?php endif; ?><form method="post" class="login-form"><?= csrf_field() ?><input type="hidden" name="action" value="login"><label>Correo electrónico<input type="email" name="email" placeholder="nombre@empresa.com" required></label><label>Contraseña<input type="password" name="password" placeholder="Ingresa tu contraseña" required></label><button class="btn btn-red w-100">Entrar al espacio <span>→</span></button></form><div class="demo-login"><span>CUENTA DE PRUEBA</span><div>admin@local.test <b>·</b> password</div></div></section><div class="login-graphic"><div class="graphic-ring"></div><div class="graphic-copy"><span>PLANEA · ASIGNA · AVANZA</span><strong>Las grandes<br>ideas se hacen<br><em>en equipo.</em></strong></div><div class="graphic-foot">PROYECTOS EN MOVIMIENTO <span>↗</span></div></div></main>
<?php else: ?>
<main class="dashboard container-fluid"><div class="page-heading"><div><span class="eyebrow">PANEL DE CONTROL / <?= e(strtoupper($role)) ?></span><h1>Hola, <?= e(explode(' ', $current['nombre'])[0]) ?> <span class="wave">✳</span></h1><p>Esto es lo que sucede en tu espacio de trabajo.</p></div><?php if (in_array($role, ['Administrador', 'Gerente de proyecto'], true)): ?><button class="btn btn-red" data-bs-toggle="modal" data-bs-target="#taskModal" onclick="prepareTask()">＋ Nueva tarea</button><?php endif; ?></div>
<?php if ($flashMessage): ?><div class="alert alert-<?= $flashMessage['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show"><?= e($flashMessage['message']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<div class="stats-row"><article class="stat-card"><span>TAREAS ASIGNADAS</span><strong><?= count($tasks) ?></strong><small>En tus proyectos</small></article><article class="stat-card"><span>EN PROCESO</span><strong><?= count(array_filter($tasks, fn($t) => $t['estado'] === 'En proceso')) ?></strong><small>Trabajo activo</small></article><article class="stat-card"><span>COMPLETADAS</span><strong><?= count(array_filter($tasks, fn($t) => $t['estado'] === 'Completado')) ?></strong><small>Buen trabajo</small></article><article class="stat-card accent-card"><span>PROYECTOS</span><strong><?= count($projects) ?></strong><small>En seguimiento</small></article></div>
<section class="section-card"><div class="section-heading"><div><span class="eyebrow">SEGUIMIENTO</span><h2>Tareas del equipo</h2></div><div class="filter-wrap"><select id="statusFilter" class="form-select form-select-sm"><option value="">Todos los estados</option><option>Pendiente</option><option>En proceso</option><option>Completado</option></select></div></div><div class="table-responsive"><table class="table task-table"><thead><tr><th>TAREA</th><th>PROYECTO</th><th>RESPONSABLE</th><th>FECHA LÍMITE</th><th>ESTADO</th><th></th></tr></thead><tbody><?php foreach ($tasks as $task): ?><tr data-status="<?= e($task['estado']) ?>"><td><strong><?= e($task['titulo']) ?></strong><small><?= e($task['descripcion']) ?></small></td><td><?= e($task['proyecto']) ?></td><td><span class="avatar"><?= e(mb_substr($task['responsable'], 0, 1)) ?></span><?= e($task['responsable']) ?></td><td><?= $task['fecha_limite'] ? e(date('d/m/Y', strtotime($task['fecha_limite']))) : '—' ?></td><td><span class="status status-<?= strtolower(str_replace(' ', '-', $task['estado'])) ?>"><?= e($task['estado']) ?></span></td><td class="actions"><?php if (in_array($role, ['Administrador','Gerente de proyecto'], true)): ?><button class="icon-button" title="Editar" data-task='<?= e(json_encode($task, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>' onclick="editTask(this)">✎</button><form method="post" onsubmit="return confirm('¿Eliminar esta tarea?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?= (int)$task['id'] ?>"><button class="icon-button danger-text" title="Eliminar">×</button></form><?php else: ?><form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?= (int)$task['id'] ?>"><select name="estado" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach (['Pendiente','En proceso','Completado'] as $state): ?><option <?= $task['estado'] === $state ? 'selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?></select></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$tasks): ?><tr><td colspan="6" class="empty-state">Aún no hay tareas. Cuando se asignen, aparecerán aquí.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="section-card project-section"><div class="section-heading"><div><span class="eyebrow">ORGANIZACIÓN</span><h2>Proyectos</h2></div><?php if ($role === 'Administrador'): ?><button class="btn btn-light-outline btn-sm" data-bs-toggle="modal" data-bs-target="#projectModal">＋ Crear proyecto</button><?php endif; ?></div><div class="project-grid"><?php foreach ($projects as $project): ?><article class="project-card"><div class="project-icon">▦</div><span class="project-number">PROYECTO <?= str_pad((string)$project['id'], 2, '0', STR_PAD_LEFT) ?></span><h3><?= e($project['nombre']) ?></h3><p><?= e($project['descripcion'] ?: 'Sin descripción') ?></p><div class="project-bottom"><span>Gerente: <?= e($project['gerente']) ?></span><?php if (in_array($role, ['Administrador','Gerente de proyecto'], true)): ?><button class="text-button" data-project="<?= (int)$project['id'] ?>" onclick="openMemberModal(this)">＋ Miembro</button><?php endif; ?></div></article><?php endforeach; ?><?php if (!$projects): ?><div class="empty-project">No tienes proyectos asignados todavía.</div><?php endif; ?></div></section>
<?php if ($role === 'Administrador'): ?><section class="section-card"><div class="section-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h2>Usuarios</h2></div><button class="btn btn-red btn-sm" data-bs-toggle="modal" data-bs-target="#userModal">＋ Nuevo usuario</button></div><div class="table-responsive"><table class="table task-table"><thead><tr><th>NOMBRE</th><th>CORREO</th><th>ROL</th><th></th></tr></thead><tbody><?php foreach ($users as $account): ?><tr><td><span class="avatar"><?= e(mb_substr($account['nombre'],0,1)) ?></span><?= e($account['nombre']) ?></td><td><?= e($account['email']) ?></td><td><span class="status status-neutral"><?= e($account['rol_nombre']) ?></span></td><td><?php if ((int)$account['id'] !== (int)$current['id']): ?><form method="post" onsubmit="return confirm('¿Eliminar este usuario?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= (int)$account['id'] ?>"><button class="icon-button danger-text">×</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
</main>
<?php if (in_array($role, ['Administrador','Gerente de proyecto'], true)): ?>
<div class="modal fade" id="taskModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="save_task"><input type="hidden" name="id" id="taskId"><div class="modal-header"><div><span class="eyebrow">GESTIÓN DEL EQUIPO</span><h2 class="modal-title">Nueva tarea</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Proyecto<select name="proyecto_id" id="taskProject" class="form-select" required><option value="">Selecciona un proyecto</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>"><?= e($project['nombre']) ?></option><?php endforeach; ?></select></label><label>Título<input name="titulo" id="taskTitle" class="form-control" maxlength="100" required></label><label>Descripción<textarea name="descripcion" id="taskDescription" class="form-control" rows="3" maxlength="500"></textarea></label><label>Asignar a<select name="usuario_id" id="taskMember" class="form-select" required><option value="">Selecciona el proyecto primero</option></select></label><label>Fecha límite<input type="date" name="fecha_limite" id="taskDate" class="form-control"></label></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-red">Guardar tarea</button></div></form></div></div>
<?php endif; ?>
<?php if ($role === 'Administrador'): ?>
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="create_user"><div class="modal-header"><div><span class="eyebrow">ACCESOS Y PERMISOS</span><h2 class="modal-title">Nuevo usuario</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Nombre<input name="nombre" class="form-control" maxlength="50" required></label><label>Correo electrónico<input type="email" name="email" class="form-control" maxlength="100" required></label><label>Contraseña<input type="password" name="password" class="form-control" minlength="8" required></label><label>Rol<select name="rol_id" class="form-select" required><?php foreach ($roles as $roleOption): ?><option value="<?= (int)$roleOption['rol_id'] ?>"><?= e($roleOption['rol_nombre']) ?></option><?php endforeach; ?></select></label></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-red">Crear usuario</button></div></form></div></div>
<div class="modal fade" id="projectModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="create_project"><div class="modal-header"><div><span class="eyebrow">ORGANIZACIÓN</span><h2 class="modal-title">Nuevo proyecto</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Nombre<input name="nombre" class="form-control" maxlength="100" required></label><label>Descripción<textarea name="descripcion" class="form-control" rows="3" maxlength="300"></textarea></label><label>Gerente<select name="gerente_id" class="form-select" required><option value="">Selecciona un gerente</option><?php foreach ($users as $account): if ($account['rol_nombre'] === 'Gerente de proyecto'): ?><option value="<?= (int)$account['id'] ?>"><?= e($account['nombre']) ?></option><?php endif; endforeach; ?></select></label></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-red">Crear proyecto</button></div></form></div></div>
<?php endif; ?>
<?php if (in_array($role, ['Administrador','Gerente de proyecto'], true)): ?><div class="modal fade" id="memberModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="add_member"><input type="hidden" name="proyecto_id" id="memberProject"><div class="modal-header"><div><span class="eyebrow">EQUIPO DE PROYECTO</span><h2 class="modal-title">Agregar miembro</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Miembro<select name="usuario_id" class="form-select" required><option value="">Selecciona un miembro</option><?php $members=$pdo->query("SELECT u.id,u.nombre FROM usuarios u JOIN roles r ON r.rol_id=u.rol_id WHERE r.rol_nombre='Miembro del equipo' ORDER BY u.nombre")->fetchAll(); foreach($members as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e($member['nombre']) ?></option><?php endforeach; ?></select></label></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-red">Agregar al proyecto</button></div></form></div></div><?php endif; ?>
<?php endif; ?>
<script>window.projectMembers = <?= json_encode($membersByProject, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="assets/app.js"></script>
</body></html>
