<?php
/**
 * index.php — Public Homepage
 * ============================================================
 
 * ============================================================
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';

secure_session_start();

// ── REDIRECT ALREADY LOGGED-IN USERS ────────────────────
// FIX: guard these with file_exists so a missing file can't cause a loop
if (is_admin()  && file_exists(__DIR__ . '/admin/dashboard.php'))  {
    header('Location: /admin/dashboard.php');
    exit;
}
if (is_member() && file_exists(__DIR__ . '/member/dashboard.php')) {
    header('Location: /member/dashboard.php');
    exit;
}
// If admin is logged in but dashboard file is missing, log them out cleanly
if (is_admin() || is_member()) {
    session_unset();
    session_destroy();
}

// Fetch public news (last 3)
$news = [];
try {
    $news = DB::get()->query(
        "SELECT * FROM news WHERE published=1 ORDER BY created_at DESC LIMIT 3"
    )->fetchAll();
} catch (Exception $e) {
    // Silently ignore if DB not ready yet
}

$showLogin = get_param('login'); // 'member' or 'admin'


// ── Header config ──────────────────────────────────────────
$pageTitle   = 'Inicio';
$pageContext = 'public';
$activeNav   = '';
require_once __DIR__ . '/includes/header.php';
?>


<!-- ── HERO ──────────────────────────────────────────────── -->
<section class="hero" aria-label="Welcome">
  <div class="hero-bg-overlay" aria-hidden="true"></div>
  <div class="hero-pattern"    aria-hidden="true"></div>
  <div class="hero-content">
    <span class="hero-symbol animate-fadeUp" aria-hidden="true"><img src="assets/img/logo.png" alt="Estrella Del Rey David #11"></span>
    <h1 class="animate-fadeUp delay-1">Estrella Del Rey David #11</h1>
    <!-- <p class="hero-subtitle animate-fadeUp delay-2">Logia Masónica  — Fundada 1952</p> -->
    <p class="hero-desc animate-fadeUp delay-3">
      Una fraternidad dedicada al crecimiento moral, espiritual e intelectual de sus miembros
      y al servicio de nuestra comunidad, sustentada en los principios de
      Fraternidad, Caridad y Verdad.
    </p>
    <div class="hero-actions animate-fadeUp delay-4">
      <button class="btn btn-gold" onclick="openModal('modal-member-login')">
        <span><i class="fas fa-star-of-david"></i></span> Acceso de Miembros
      </button>
      <a href="#about" class="btn btn-outline">Conoce la Logia</a>
    </div>
  </div>
  <div class="hero-scroll" aria-hidden="true">↓</div>
</section>

<!-- ── ABOUT / PILLARS ───────────────────────────────────── -->
<section class="section" id="about">
  <div class="section-inner">
    <h2 class="section-title animate-fadeUp">Nuestros Ideales</h2>
    <p class="section-sub">Fraternidad · Caridad · Verdad</p>
    <div class="divider"></div>
    <div class="pillars-grid">
      <article class="card pillar animate-fadeUp delay-1">
        <div class="pillar-icon" aria-hidden="true">⚖</div>
        <h3>Amor Fraternal</h3>
        <p>Consideramos a la humanidad entera como una sola familia. Nos esforzamos en practicar la tolerancia, el respeto y la comprensión hacia todos nuestros semejantes.</p>
      </article>
      <article class="card pillar animate-fadeUp delay-2">
        <div class="pillar-icon" aria-hidden="true">✦</div>
        <h3>Auxilio</h3>
        <p>Es nuestro deber aliviar la angustia de los necesitados. En cada caso de necesidad, extendemos la mano de la generosidad y la caridad fraterna.</p>
      </article>
      <article class="card pillar animate-fadeUp delay-3">
        <div class="pillar-icon" aria-hidden="true">◈</div>
        <h3>Verdad</h3>
        <p>Buscamos la sabiduría y el conocimiento a lo largo de nuestro camino masónico, comprometidos con la integridad y la mejora constante del ser humano.</p>
      </article>
    </div>
  </div>
</section>

<!-- ── HISTORY ───────────────────────────────────────────── -->
<section class="section section-dark" id="history">
  <div class="section-inner">
    <h2 class="section-title">Historia de la Logia</h2>
    <p class="section-sub">Más de 70 años de Fraternidad</p>
    <div class="divider"></div>
    <div class="grid-2">
      <div>
        <div class="timeline animate-fadeUp">
          <div class="timeline-item">
            <div class="timeline-year">1952</div>
            <div class="timeline-text">Fundación de la Logia Estrella Del Rey David #11. Un grupo de visionarios comprometidos con los valores masónicos establecen la hermandad.</div>
          </div>
          <div class="timeline-item">
            <div class="timeline-year">1970s</div>
            <div class="timeline-text">Crecimiento significativo en membresía. La logia consolida su presencia en la comunidad con programas de beneficencia y educación.</div>
          </div>
          <div class="timeline-item">
            <div class="timeline-year">1990s</div>
            <div class="timeline-text">Renovación del templo y expansión de los programas comunitarios. Se establecen fondos para becas estudiantiles.</div>
          </div>
          <div class="timeline-item">
            <div class="timeline-year">Hoy</div>
            <div class="timeline-text">Con más de 70 años de historia, seguimos trabajando en la formación moral e intelectual de nuestros hermanos y en el servicio a la comunidad.</div>
          </div>
        </div>
      </div>
      <div class="stats-grid stats-align-top">
        <div class="stat-card animate-fadeUp delay-1">
          <div class="stat-label">Años de Historia</div>
          <div class="stat-value"><?= date('Y') - 1952 ?></div>
        </div>
        <div class="stat-card animate-fadeUp delay-2">
          <div class="stat-label">Hermanos Activos</div>
          <div class="stat-value"><?php
            try { echo DB::get()->query("SELECT COUNT(*) FROM members WHERE active=1")->fetchColumn(); }
            catch(Exception $e) { echo '—'; }
          ?></div>
        </div>
        <div class="stat-card animate-fadeUp delay-3">
          <div class="stat-label">Programas Comunitarios</div>
          <div class="stat-value">12</div>
        </div>
        <div class="stat-card animate-fadeUp delay-4">
          <div class="stat-label">Grado Máximo</div>
          <div class="stat-value">33°</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── NEWS ──────────────────────────────────────────────── -->
<section class="section" id="news">
  <div class="section-inner">
    <h2 class="section-title">Comunicados</h2>
    <p class="section-sub">Últimas noticias de la Logia</p>
    <div class="divider"></div>
    <?php if (empty($news)): ?>
      <p class="empty-state">No hay comunicados disponibles en este momento.</p>
    <?php else: ?>
    <div class="grid-3">
      <?php foreach ($news as $n): ?>
      <article class="card card-gold animate-fadeUp">
        <div class="news-date"><?= e(date('d M Y', strtotime($n['created_at']))) ?> · <?= e($n['author']) ?></div>
        <h3 class="news-title"><?= e($n['title']) ?></h3>
        <p class="news-body"><?= e(mb_substr($n['body'], 0, 180)) . (mb_strlen($n['body']) > 180 ? '…' : '') ?></p>
        <div class="mt-2">
          <button class="btn btn-outline btn-sm" onclick="openModal('modal-member-login')">Leer más →</button>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ── CONTACT ───────────────────────────────────────────── -->
<section class="section section-dark" id="contact">
  <div class="section-inner section-inner-sm">
    <h2 class="section-title">Contacto</h2>
    <p class="section-sub">¿Deseas conocer más sobre la Masonería?</p>
    <div class="divider"></div>
    <div class="card contact-card">
      <p>
        La Logia Estrella Del Rey David #11 da la bienvenida a hombres de buena moral
        que deseen conocer nuestros principios y ser parte de nuestra hermandad.
        Para mayor información, contáctanos a través de un miembro activo de la logia.
      </p>
      <div class="contact-actions">
        <button class="btn btn-gold" onclick="openModal('modal-member-login')">Acceso de Miembros</button>
        <button class="btn btn-outline" onclick="openModal('modal-admin-login')">Admin</button>
      </div>
    </div>
  </div>
</section>


<?php
// ── Footer ────────────────────────────────────────────────
require_once __DIR__ . '/includes/footer.php';