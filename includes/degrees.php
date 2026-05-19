<?php
/**
 * includes/degrees.php — Scottish Rite 33 Degrees
 * Used in member add/edit forms
 */

define('SCOTTISH_RITE_DEGREES', [
    1  => 'Entered Apprentice',
    2  => 'Fellow Craft',
    3  => 'Master Mason',
    4  => 'Secret Master',
    5  => 'Perfect Master',
    6  => 'Intimate Secretary',
    7  => 'Provost and Judge',
    8  => 'Intendant of the Building',
    9  => 'Elu of the Nine',
    10 => 'Elu of the Fifteen',
    11 => 'Elu of the Twelve',
    12 => 'Master Architect',
    13 => 'Royal Arch of Solomon',
    14 => 'Perfect Elu',
    15 => 'Knight of the East (Knight of the Sword)',
    16 => 'Prince of Jerusalem',
    17 => 'Knight of the East and West',
    18 => 'Knight Rose Croix',
    19 => 'Grand Pontiff',
    20 => 'Master of the Symbolic Lodge',
    21 => 'Noachite or Prussian Knight',
    22 => 'Knight of the Royal Axe (Prince of Libanus)',
    23 => 'Chief of the Tabernacle',
    24 => 'Prince of the Tabernacle',
    25 => 'Knight of the Brazen Serpent',
    26 => 'Prince of Mercy',
    27 => 'Knight Commander of the Temple',
    28 => 'Knight of the Sun (Prince Adept)',
    29 => 'Scottish Knight of Saint Andrew',
    30 => 'Knight Kadosh (Knight of the White and Black Eagle)',
    31 => 'Inspector Inquisitor',
    32 => 'Master of the Royal Secret',
    33 => 'Sovereign Grand Inspector General',
]);

/** Return <option> HTML for a <select>, with optional selected degree */
function degrees_options(int $selected = 1): string {
    $html = '';
    foreach (SCOTTISH_RITE_DEGREES as $num => $name) {
        $sel = $selected === $num ? ' selected' : '';
        $html .= sprintf('<option value="%d"%s>%d° — %s</option>', $num, $sel, $num, htmlspecialchars($name));
    }
    return $html;
}