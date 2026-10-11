<?php
if (!defined('ABSPATH')) exit;

trait ArcWind_Init_Feedback_Trait {
    public function render_feedback_enabled() {
        $o = $this->options();
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[feedback_enabled]" value="1" ' . checked(!isset($o['feedback_enabled']) || $o['feedback_enabled'], true, false) . '> Aktivera feedback</label>';
    }

    public function render_input_feedback_uuid()     { $o = $this->options(); ?>
        <input type="text" style="width:260px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[feedback_uuid]" value="<?php echo esc_attr($o['feedback_uuid'] ?? ''); ?>" placeholder="MM0H1A5A" /><?php
    }
    public function render_feedback_widget() {
        $o = $this->options();
        $uuid = $o['feedback_uuid'] ?? '';
        $url  = $uuid ? ('https://t.arc.vin/s/' . rawurlencode($uuid)) : '';
        echo '<p>Öppna formuläret för att rapportera fel eller önska en ny funktion.</p>';
        if ($uuid) {
            echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url($url) . '">Öppna feedbackformulär</a></p>';
        } else {
            echo '<p><em>Ange din UUID under <a href="' . esc_url(admin_url('options-general.php?page=arcwind_init')) . '">Inställningar → ArcWind Init</a> för att visa knappen.</em></p>';
        }
    }
}
