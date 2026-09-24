<?php
// Requiere $activePage definido antes del include ('dashboard' | 'accounts' | 'users' | 'hidden-tokens' | 'commercials' | 'settings').
// Requiere sesión iniciada (usa $_SESSION).
$activePage = $activePage ?? '';
function nav_class(string $page, string $active): string
{
    return $page === $active ? 'active' : '';
}
?>
<nav class="sidebar">
  <div class="brand">CondorDeFi</div>

  <a class="<?= nav_class('dashboard', $activePage) ?>" href="/dashboard.php">Inicio</a>
  <a class="<?= nav_class('accounts', $activePage) ?>" href="/accounts.php">Cuentas</a>
  <?php if (!empty($_SESSION['is_admin'])): ?>
    <a class="<?= nav_class('users', $activePage) ?>" href="/users.php">Usuarios</a>
    <a class="<?= nav_class('commercials', $activePage) ?>" href="/commercials.php">Comerciales</a>
    <a class="<?= nav_class('hidden-tokens', $activePage) ?>" href="/hidden-tokens.php">Tokens ocultos</a>
    <a class="<?= nav_class('settings', $activePage) ?>" href="/settings.php">Configuración</a>
  <?php endif; ?>

  <div class="spacer"></div>

  <div class="user"><?= htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES) ?></div>
  <a href="/logout.php">Cerrar sesión</a>
</nav>
