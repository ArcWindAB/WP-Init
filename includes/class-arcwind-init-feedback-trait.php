<?php
if (!defined('ABSPATH')) exit;

trait ArcWind_Init_Feedback_Trait {
    public function render_feedback_enabled() {
        $o = $this->options();
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[feedback_enabled]" value="1" ' . checked(!isset($o['feedback_enabled']) || $o['feedback_enabled'], true, false) . '> ' . esc_html__('Aktivera feedback', 'arcwind-init') . '</label>';
    }

    public function render_input_feedback_uuid()     { $o = $this->options(); ?>
        <input type="text" style="width:260px" name="<?php echo esc_attr(self::OPTION_KEY); ?>[feedback_uuid]" aria-label="<?php echo esc_attr__('Feedback-UUID', 'arcwind-init'); ?>" value="<?php echo esc_attr($o['feedback_uuid'] ?? ''); ?>" placeholder="MM0H1A5A" /><?php
    }
    public function render_feedback_widget() {
        $o = $this->options();
        $uuid = $o['feedback_uuid'] ?? '';
        $url  = $uuid ? ('https://t.arc.vin/s/' . rawurlencode($uuid)) : '';
        echo '<p>' . esc_html__('Öppna formuläret för att rapportera fel eller önska en ny funktion.', 'arcwind-init') . '</p>';
        if ($uuid) {
            echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url($url) . '">' . esc_html__('Öppna feedbackformulär', 'arcwind-init') . '</a></p>';
        } else {
            echo '<p><em>' . esc_html__('Ange ditt feedback-ID under', 'arcwind-init') . ' <a href="' . esc_url(admin_url('options-general.php?page=arcwind_init')) . '">' . esc_html__('Inställningar → ArcWind Init', 'arcwind-init') . '</a> ' . esc_html__('för att visa knappen.', 'arcwind-init') . '</em></p>';
        }
    }
}
