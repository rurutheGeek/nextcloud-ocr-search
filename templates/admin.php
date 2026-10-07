<?php
/**
 * @var \OCP\IL10N $l
 * @var array $_ the template parameters from Settings\Admin
 */
?>
<div id="ocr_search" class="section">
	<h2><?php p($l->t('OCR Search')); ?></h2>
	<?php if ($_['status'] === 'ok'): ?>
		<p class="success"><?php p($l->t('Saved. The OCR service answered.')); ?></p>
	<?php elseif ($_['status'] === 'unreachable'): ?>
		<p class="warning"><?php p($l->t('Saved. The OCR service did not answer.')); ?></p>
	<?php elseif ($_['status'] === 'incomplete'): ?>
		<p class="warning"><?php p($l->t('Saved. The service URL or the token is missing.')); ?></p>
	<?php endif; ?>
	<p>
		<?php p($l->t('Recognised: %1$s · Waiting: %2$s · Failed: %3$s · Skipped: %4$s', [
			$_['counts']['done'], $_['counts']['pending'], $_['counts']['failed'], $_['counts']['skipped'],
		])); ?>
	</p>
	<form method="post" action="<?php p($_['save_url']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<p>
			<label for="ocr_search_url"><?php p($l->t('Service URL')); ?></label><br>
			<input type="url" id="ocr_search_url" name="ocrUrl" class="text"
				style="width: 24em" value="<?php p($_['ocr_url']); ?>" placeholder="http://ocr:8080">
		</p>
		<p>
			<label for="ocr_search_token"><?php p($l->t('Service token')); ?></label><br>
			<input type="password" id="ocr_search_token" name="ocrToken" class="text"
				style="width: 24em" value="" autocomplete="new-password"
				placeholder="<?php p($_['token_set'] ? $l->t('stored — leave empty to keep') : ''); ?>">
		</p>
		<?php if ($_['token_set']): ?>
			<p>
				<label>
					<input type="checkbox" name="clearToken" value="1">
					<?php p($l->t('Remove the stored token')); ?>
				</label>
			</p>
		<?php endif; ?>
		<p>
			<label for="ocr_search_max_side"><?php p($l->t('Longest side sent to recognition (pixels)')); ?></label><br>
			<input type="number" id="ocr_search_max_side" name="maxSide" class="text"
				min="256" max="4096" value="<?php p($_['max_side']); ?>">
		</p>
		<p>
			<label for="ocr_search_mime_types"><?php p($l->t('Image types')); ?></label><br>
			<input type="text" id="ocr_search_mime_types" name="mimeTypes" class="text"
				style="width: 40em" value="<?php p($_['mime_types']); ?>">
		</p>
		<p>
			<label for="ocr_search_min_size"><?php p($l->t('Smallest file (bytes)')); ?></label><br>
			<input type="number" id="ocr_search_min_size" name="minSize" class="text"
				min="0" value="<?php p($_['min_size']); ?>">
		</p>
		<p>
			<label for="ocr_search_max_size"><?php p($l->t('Largest file (bytes)')); ?></label><br>
			<input type="number" id="ocr_search_max_size" name="maxSize" class="text"
				min="1" value="<?php p($_['max_size']); ?>">
		</p>
		<button type="submit" class="button primary"><?php p($l->t('Save')); ?></button>
	</form>
</div>
