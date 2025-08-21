<tr>
    <th scope="row"><?php esc_html_e('Backup', 'tiny-compress-images') ?></th>
    <td>
        <p class="tiny-check">
            <input type="checkbox" name="" value="" />
            <label for="">
                <?php esc_html_e('Save a copy of the original uncompressed image', 'tiny-compress-images'); ?>
            </label>
        </p>
        <p class="intro">
            <?php
            esc_html_e(
                'When enabled, the original image will be backed up as <original_filename>.tiny-backup.',
                'tiny-compress-images'
            )
            ?>
        </p>
    </td>
</tr>