<?php
/**
 * includes/footer.php — Global Page Footer
 * ============================================================
 * Include at the VERY BOTTOM of every page — outputs the
 * footer, app.js, and closing </body></html>.
 *
 * Optional variables (set before including):
 *   $footerNote   = 'Panel Administrativo · Confidencial';
 *   $extraScripts = '<script src="/assets/js/page.js"></script>';
 * ============================================================
 */
if (!isset($footerNote))   $footerNote   = '';
if (!isset($extraScripts)) $extraScripts = '';
?>

<footer <?= $pageContext === 'admin' ? 'style="margin-top:0"' : '' ?> role="contentinfo">
  <span class="footer-symbol"><i class="fas fa-star-of-david"></i></span>
  <div class="footer-name">Estrella Del Rey David No. 11</div>
  <p style="color:var(--text-muted);font-size:13px;margin-top:.35rem">Fraternidad · Caridad · Verdad</p>
  <?php if ($footerNote): ?>
  <p style="color:var(--text-muted);font-size:11px;margin-top:.2rem;opacity:.7"><?= e($footerNote) ?></p>
  <?php endif; ?>
  <p class="footer-copy">© <?= date('Y') ?> Estrella Del Rey David No. 11 · Todos los derechos reservados</p>
</footer>

<script src="/assets/js/app.js?v=1.12"></script>
<?php if ($extraScripts) echo $extraScripts; ?>
</body>
</html>