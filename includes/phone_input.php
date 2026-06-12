<?php
/**
 * phone_input.php — Reusable international phone input component.
 *
 * Usage:
 *   $phone_field_name  = 'phone';            // the POST field name
 *   $phone_field_value = $patient['phone'];  // pre-filled value
 *   $phone_field_label = 'Phone';            // label text
 *   $phone_required    = true;               // required attribute
 *   include '../../includes/phone_input.php';  (adjust path as needed)
 *
 * The component outputs:
 *   - A hidden input named $phone_field_name containing the full E.164 number
 *   - A flag + dial-code selector
 *   - A local-number text input
 * On submit the JS merges them into the hidden field.
 */

$_pf_name     = $phone_field_name  ?? 'phone';
$_pf_value    = $phone_field_value ?? '';
$_pf_label    = $phone_field_label ?? 'Phone';
$_pf_required = $phone_field_required ?? true;
$_pf_id       = 'pf_' . preg_replace('/[^a-z0-9]/', '_', $_pf_name);

// Parse existing value to pre-select country
$_pf_dialcode = '+63';   // default PH
$_pf_local    = '';

if (!empty($_pf_value)) {
    if (preg_match('/^(\+\d{1,4})(\d+)$/', $_pf_value, $m)) {
        $_pf_dialcode = $m[1];
        $_pf_local    = $m[2];
    } elseif (preg_match('/^09\d{9}$/', $_pf_value)) {
        // Legacy PH local → convert display
        $_pf_dialcode = '+63';
        $_pf_local    = '9' . substr($_pf_value, 2);
    } else {
        $_pf_local = $_pf_value;
    }
}

// Country list: [display name, dial code, flag emoji, max local digits]
$_pf_countries = [
    ['Philippines',        '+63',  '🇵🇭', 10],
    ['United States',      '+1',   '🇺🇸', 10],
    ['Canada',             '+1',   '🇨🇦', 10],
    ['China',              '+86',  '🇨🇳', 11],
    ['Japan',              '+81',  '🇯🇵', 10],
    ['South Korea',        '+82',  '🇰🇷', 10],
    ['Australia',          '+61',  '🇦🇺', 9 ],
    ['United Kingdom',     '+44',  '🇬🇧', 10],
    ['Germany',            '+49',  '🇩🇪', 11],
    ['France',             '+33',  '🇫🇷', 9 ],
    ['India',              '+91',  '🇮🇳', 10],
    ['Indonesia',          '+62',  '🇮🇩', 11],
    ['Malaysia',           '+60',  '🇲🇾', 9 ],
    ['Singapore',          '+65',  '🇸🇬', 8 ],
    ['Thailand',           '+66',  '🇹🇭', 9 ],
    ['Vietnam',            '+84',  '🇻🇳', 9 ],
    ['Hong Kong',          '+852', '🇭🇰', 8 ],
    ['Taiwan',             '+886', '🇹🇼', 9 ],
    ['Saudi Arabia',       '+966', '🇸🇦', 9 ],
    ['UAE',                '+971', '🇦🇪', 9 ],
    ['Brazil',             '+55',  '🇧🇷', 11],
    ['Mexico',             '+52',  '🇲🇽', 10],
    ['Nigeria',            '+234', '🇳🇬', 10],
    ['South Africa',       '+27',  '🇿🇦', 9 ],
];
?>
<div class="phone-input-wrap" id="<?php echo $_pf_id; ?>_wrap" style="position:relative;">
    <label class="form-label">
        <?php echo htmlspecialchars($_pf_label); ?>
        <?php if ($_pf_required): ?><span style="color:var(--danger)">*</span><?php endif; ?>
    </label>
    <!-- Hidden field that holds the full E.164 number submitted to server -->
    <input type="hidden"
           name="<?php echo htmlspecialchars($_pf_name); ?>"
           id="<?php echo $_pf_id; ?>_full"
           value="<?php echo htmlspecialchars($_pf_value); ?>">

    <div style="display:flex;gap:0;">
        <!-- Country selector -->
        <select id="<?php echo $_pf_id; ?>_country"
                class="form-select"
                style="width:auto;min-width:130px;max-width:160px;border-radius:var(--border-radius-md) 0 0 var(--border-radius-md);border-right:0;flex-shrink:0;font-size:0.875rem;"
                onchange="phoneInputSync('<?php echo $_pf_id; ?>')">
            <?php foreach ($_pf_countries as [$name, $code, $flag, $maxlen]): ?>
            <option value="<?php echo htmlspecialchars($code); ?>"
                    data-maxlen="<?php echo $maxlen; ?>"
                    <?php echo $_pf_dialcode === $code ? 'selected' : ''; ?>>
                <?php echo $flag . ' ' . $code . ' ' . $name; ?>
            </option>
            <?php endforeach; ?>
        </select>
        <!-- Local number input -->
        <input type="text"
               id="<?php echo $_pf_id; ?>_local"
               class="form-control"
               style="border-radius:0 var(--border-radius-md) var(--border-radius-md) 0;flex:1;"
               placeholder="Local number"
               value="<?php echo htmlspecialchars($_pf_local); ?>"
               <?php if ($_pf_required): ?>required<?php endif; ?>
               maxlength="15"
               inputmode="numeric"
               oninput="phoneInputSync('<?php echo $_pf_id; ?>')"
               onkeypress="return /[0-9]/.test(event.key)">
    </div>
    <div id="<?php echo $_pf_id; ?>_hint"
         style="font-size:0.75rem;color:var(--gray-400);margin-top:3px;">
        <?php echo htmlspecialchars($_pf_dialcode); ?> + local number without leading zero
    </div>
</div>

<?php // JS is output once globally via phone_input_js() — call it once at bottom of page ?>
