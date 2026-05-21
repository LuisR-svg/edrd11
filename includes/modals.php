<!-- ══ LOGIN MODALS ══════════════════════════════════════ -->

<?php /* ══════════════════════════════════════════════
         LOGIN MODALS — only rendered on public pages.
         Members and admins are already logged in.
         ══════════════════════════════════════════════ */ ?>
<?php if ($pageContext === 'public'): ?>

<!-- Member Login Modal -->
 <div id="modal-member-login" class="modal-overlay" style="display:none"
     role="dialog" aria-modal="true" aria-labelledby="member-login-title">
  <div class="modal">
    <span class="login-symbol" aria-hidden="true">⬡</span>
    <h2 class="modal-title" id="member-login-title">Acceso de Miembros</h2>
    <p class="login-sub">Estrella Del Rey David No. 11</p>
    <?php if (!empty($_SESSION['login_error_member'])): ?>
      <div class="form-error auto-dismiss"><?= e($_SESSION['login_error_member']) ?></div>
      <?php unset($_SESSION['login_error_member']); ?>
    <?php endif; ?>
    <form method="POST" action="/api/auth.php" autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="member">
      <div class="form-group">
        <label class="form-label" for="member-email">Correo Electrónico</label>
        <input type="email" id="member-email" name="email" class="form-control"
               placeholder="tu@correo.com" required autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label" for="member-pin">PIN de Acceso</label>
        <input type="password" id="member-pin" name="pin" class="form-control"
               placeholder="••••" maxlength="8" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-gold btn-full" style="margin-top:1rem">
        Entrar a la Logia
      </button>
    </form>
    <button class="btn btn-outline btn-full" style="margin-top:.75rem"
            onclick="closeModal('modal-member-login')">Cancelar</button>
  </div>
</div>

<!-- Admin Login Modal -->
<div id="modal-admin-login" class="modal-overlay" style="display:none"
     role="dialog" aria-modal="true" aria-labelledby="admin-login-title">
  <div class="modal">
    <span class="login-symbol" aria-hidden="true">⬡</span>
    <h2 class="modal-title" id="admin-login-title">Acceso Administrativo</h2>
    <p class="login-sub">Solo personal autorizado</p>
    <?php if (!empty($_SESSION['login_error_admin'])): ?>
      <div class="form-error auto-dismiss"><?= e($_SESSION['login_error_admin']) ?></div>
      <?php unset($_SESSION['login_error_admin']); ?>
    <?php endif; ?>
    <form method="POST" action="/api/auth.php" autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="admin">
      <div class="form-group">
        <label class="form-label" for="admin-username">Usuario</label>
        <input type="text" id="admin-username" name="username" class="form-control"
               placeholder="admin" required autocomplete="username">
      </div>
      <div class="form-group">
        <label class="form-label" for="admin-password">Contraseña</label>
        <input type="password" id="admin-password" name="password" class="form-control"
               placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-gold btn-full" style="margin-top:1rem">
        Ingresar
      </button>
    </form>
    <button class="btn btn-outline btn-full" style="margin-top:.75rem"
            onclick="closeModal('modal-admin-login')">Cancelar</button>
  </div>
</div>

<?php /* Auto-open a modal if URL has ?login=member or ?login=admin */ ?>
<?php
$_autoLogin = trim(strip_tags($_GET['login'] ?? ''));
if ($_autoLogin === 'member'): ?>
<script>document.addEventListener('DOMContentLoaded', () => openModal('modal-member-login'));</script>
<?php elseif ($_autoLogin === 'admin'): ?>
<script>document.addEventListener('DOMContentLoaded', () => openModal('modal-admin-login'));</script>
<?php endif; ?>

<?php endif; // end public-only modals ?>
