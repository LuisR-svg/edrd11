<?php
/**
 * includes/footer.php — Global Page Footer
 * ============================================================
 * Include this at the BOTTOM of every page to get:
 *   - Footer HTML with lodge name, tagline, copyright
 *   - app.js <script> tag
 *   - Closing </body></html>
 *
 * HOW TO USE — place at the very end of every page:
 * --------------------------------------------------------
 *   require_once __DIR__ . '/includes/footer.php';          // from root
 *   require_once __DIR__ . '/../includes/footer.php';       // from /member/ or /admin/
 *   require_once __DIR__ . '/../../includes/footer.php';    // from deeper folders
 *
 * OPTIONAL — pass variables before including:
 * --------------------------------------------------------
 *   $footerTagline = 'Fraternidad · Caridad · Verdad'; // default shown below
 *   $footerNote    = 'Panel Administrativo · Confidencial'; // extra note line
 *   $extraScripts  = '<script src="/assets/js/charts.js"></script>'; // extra JS
 * ============================================================
 */

// ── Defaults ─────────────────────────────────────────────
if (!isset($footerTagline)) $footerTagline = 'Fraternidad · Caridad · Verdad';
if (!isset($footerNote))    $footerNote    = '';
if (!isset($extraScripts))  $extraScripts  = '';
?>

<!-- ═══════════════════════════════════════════════════════
     GLOBAL FOOTER — edit here, changes apply to all pages
     ═══════════════════════════════════════════════════════ -->
<footer role="contentinfo">
  <span class="footer-symbol" aria-hidden="true">⬡</span>

  <!-- Lodge name — edit once here to change everywhere -->
  <div class="footer-name">Estrella Del Rey David Numero 11</div>

  <!-- Tagline — change $footerTagline before including, or edit default above -->
  <?php if ($footerTagline): ?>
  <p style="color:var(--text-muted);font-size:13px;margin-top:.35rem">
    <?= e($footerTagline) ?>
  </p>
  <?php endif; ?>

  <!-- Optional extra note (e.g. "Confidential" on admin pages) -->
  <?php if ($footerNote): ?>
  <p style="color:var(--text-muted);font-size:11px;margin-top:.2rem;opacity:.7">
    <?= e($footerNote) ?>
  </p>
  <?php endif; ?>

  <!-- Copyright — year updates automatically -->
  <p class="footer-copy">
    © <?= date('Y') ?> Estrella Del Rey David Numero 11 · Todos los derechos reservados
  </p>
</footer>

<!-- Global JS — loaded last so DOM is ready -->
<script src="/assets/js/app.js"></script>

<?php if ($extraScripts) echo $extraScripts; ?>

</body>
</html>