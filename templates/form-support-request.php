<?php
/**
 * Support request form template.
 *
 * @package SupportRequestFrontend
 */

if (!defined('ABSPATH')) {
	exit;
}

$kgr_confirmation_result = null;
$kgr_confirmation_token = '';
if (isset($_GET['kgr_confirm_token']) || isset($_GET['token'])) {
	$raw_token = isset($_GET['kgr_confirm_token']) ? wp_unslash((string) $_GET['kgr_confirm_token']) : wp_unslash((string) $_GET['token']);
	$kgr_confirmation_token = sanitize_text_field($raw_token);

	if ($kgr_confirmation_token !== '') {
		if (!class_exists(\App\services\SupportRequestService::class)) {
			$bootstrap_path = KGR_PLUGIN_PATH . 'backend/src/bootstrap.php';
			if (file_exists($bootstrap_path)) {
				require_once $bootstrap_path;
			}
		}

		if (class_exists(\App\services\SupportRequestService::class)) {
			try {
				$kgr_confirmation_result = (new \App\services\SupportRequestService())->confirm($kgr_confirmation_token);
			} catch (\Throwable $e) {
				if (class_exists(\App\helpers\Logger::class)) {
					\App\helpers\Logger::error('WP page confirmation flow failed', ['error' => $e->getMessage()]);
				}
				$kgr_confirmation_result = array(
					'success' => false,
					'message' => __('An unexpected error occurred while confirming your request.', 'kanguru-support'),
				);
			}
		} else {
			$kgr_confirmation_result = array(
				'success' => false,
				'message' => __('Confirmation service is not available right now.', 'kanguru-support'),
			);
		}
	}
}

$kgr_clean_page_url = remove_query_arg(array('kgr_confirm_token', 'token'));

if (is_array($kgr_confirmation_result)):
	$kgr_success = !empty($kgr_confirmation_result['success']);
	$kgr_title = $kgr_success
		? __('Request Confirmed', 'kanguru-support')
		: __('Confirmation Failed', 'kanguru-support');
	$kgr_message = (string) ($kgr_confirmation_result['message'] ?? '');
	?>
	<section class="<?php echo esc_attr($wrapper_class); ?>" data-kgr-root>
		<div class="kgr-shell">
			<div class="kgr-layout kgr-mode--single">
				<div class="kgr-layout__main">
					<div class="kgr-submit-state">
						<div class="kgr-submit-state__icon" aria-hidden="true"><?php echo $kgr_success ? '✓' : '!'; ?></div>
						<h3><?php echo esc_html($kgr_title); ?></h3>
						<p><?php echo esc_html($kgr_message); ?></p>
						<div class="kgr-actions kgr-actions--center" style="margin-top: 20px;">
							<a class="kgr-btn kgr-btn--secondary" href="<?php echo esc_url($kgr_clean_page_url); ?>">
								<?php esc_html_e('Back to support form', 'kanguru-support'); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>
	<?php
	return;
endif;
?>
<section class="<?php echo esc_attr($wrapper_class); ?>" data-kgr-root x-data="kgrData()">
	<div class="kgr-shell">
		<header class="kgr-shell__header">
			<div class="kgr-progress" aria-live="polite" style="display:none;">
				<span class="kgr-progress__count" data-kgr-step-counter>1/6</span>
				<span class="kgr-progress__label"
					data-kgr-step-label><?php esc_html_e('Step 1 of 6', 'kanguru-support'); ?></span>
			</div>
		</header>

		<div class="kgr-alert kgr-alert--hidden" data-kgr-alert role="alert" aria-live="assertive"></div>

		<div class="kgr-layout kgr-mode--full">
			<div class="kgr-layout__main">
				<form class="kgr-form" data-kgr-form novalidate autocomplete="off">
					<section class="kgr-step is-active" data-step-key="service" aria-hidden="false">
						<div class="kgr-step__head">
							<div class="kgr-progress-wrapper">
								<h3><?php esc_html_e('What service do you have with us?', 'kanguru-support'); ?>
								</h3>
								<div class="kgr-progress" aria-live="polite" style="display: none;">
									<span class="kgr-progress__count" data-kgr-step-counter>1/6</span>
								</div>
							</div>
						</div>
						<div class="kgr-field">
							<select id="kgr_service_type" name="service_type" data-error-key="service_type"
								x-model='service_type' required>
								<option value="">
									<?php esc_html_e('Please choose one...', 'kanguru-support'); ?>
								</option>
								<option value="Website"><?php esc_html_e('Website', 'kanguru-support'); ?>
								</option>
								<option value="Digital Marketing"><?php esc_html_e('Digital Marketing', 'kanguru-support'); ?></option>
								<option value="Design"><?php esc_html_e('Design', 'kanguru-support'); ?>
								</option>
								<option value="Other"><?php esc_html_e('Other', 'kanguru-support'); ?></option>
							</select>
							<p class="kgr-error" data-field-error-for="service_type" id="error_service_type"></p>
						</div>
						<div class="kgr-actions">
							<button type="button" class="kgr-btn kgr-btn--primary" data-action="next">
								<span class="kgr-btn__spinner kgr-hidden" data-next-spinner></span>
								<span data-next-label><?php esc_html_e('Next', 'kanguru-support'); ?></span>
								<img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/next.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--after" />
							</button>
						</div>
					</section>

					<section class="kgr-step" data-step-key="validation" aria-hidden="true">
						<div class="kgr-step__head">
							<div class="kgr-progress-wrapper">
								<h3><?php esc_html_e('Which website is this for?', 'kanguru-support'); ?></h3>
								<div class="kgr-progress" aria-live="polite" style="display: none;">
									<span class="kgr-progress__count" data-kgr-step-counter>2/6</span>
								</div>
							</div>
						</div>

						<div class="kgr-branch kgr-branch--website kgr-hidden" data-branch="website-validation">
							<div class="kgr-field">
								
								<input id="kgr_website_url" name="website_url" type="text" inputmode="url"
									placeholder="<?php esc_attr_e('e.g., www.mywebsite.com', 'kanguru-support'); ?>" data-error-key="selected_domain"
									x-model='website_url' autocomplete="url" />
								<p class="kgr-error" data-field-error-for="selected_domain"></p>
								<p class="kgr-error" data-field-error-for="website_url" id="error_website_url"></p>
							</div>
							<p class="kgr-hint kgr-hidden" data-kgr-qa-hint>
								<?php esc_html_e('QA Hint: as a website you can use', 'kanguru-support'); ?>
								<button type="button" class="kgr-qa-copy" data-kgr-copy-text="www.test-support.com" data-kgr-copy-label="<?php esc_attr_e('Copied', 'kanguru-support'); ?>">
									<strong>www.test-support.com</strong>
									<span class="kgr-qa-copy__toast" aria-live="polite" aria-hidden="true"><?php esc_html_e('Copied', 'kanguru-support'); ?></span>
								</button>
							</p>
						</div>
						<div class="kgr-branch kgr-branch--non-website kgr-hidden" data-branch="non-website-routing">
							<div class="kgr-note">
								<strong><?php esc_html_e('No website validation required.', 'kanguru-support'); ?></strong>
								<p><?php esc_html_e('You selected a non-website service, so we continue to contact details.', 'kanguru-support'); ?>
								</p>
							</div>
						</div>
						<div class="kgr-actions">
							<button type="button" class="kgr-btn kgr-btn--secondary"
								data-action="back"><img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/back.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--before" /><?php esc_html_e('Back', 'kanguru-support'); ?></button>
							<button type="button" class="kgr-btn kgr-btn--primary" data-action="next">
								<span class="kgr-btn__spinner kgr-hidden" data-next-spinner></span>
								<span data-next-label><?php esc_html_e('Next', 'kanguru-support'); ?></span>
								<img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/next.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--after" />
							</button>
							<a href="https://kanguru.ca/contact-us/" class="kgr-btn kgr-btn--primary kgr-hidden"
								data-kgr-contact-btn target="_blank">
								<?php esc_html_e('Contact us', 'kanguru-support'); ?>
							</a>
						</div>
					</section>

					<section class="kgr-step" data-step-key="contact" aria-hidden="true">
						<div class="kgr-step__head">
							<div class="kgr-progress-wrapper">
								<h3><?php esc_html_e('Who are we speaking with?', 'kanguru-support'); ?></h3>
								<div class="kgr-progress" aria-live="polite" style="display: none;">
									<span class="kgr-progress__count" data-kgr-step-counter>3/6</span>
								</div>
							</div>
						</div>
						<div class="kgr-field">
							
							<input id="kgr_email" name="email" type="email" data-error-key="email" autocomplete="email" placeholder="<?php esc_attr_e('Your email address', 'kanguru-support'); ?>"
								x-model='email' />
							<p class="kgr-error" data-field-error-for="email" id="error_email"></p>
						</div>
						<div class="kgr-grid kgr-grid--two">
							<div class="kgr-field">
								
								<input id="kgr_first_name" name="first_name" type="text" data-error-key="first_name" placeholder="<?php esc_attr_e('First name', 'kanguru-support'); ?>"
									autocomplete="given-name" x-model='first_name' />
								<p class="kgr-error" data-field-error-for="first_name" id="error_first_name"></p>
							</div>
							<div class="kgr-field">
								
								<input id="kgr_last_name" name="last_name" type="text" data-error-key="last_name" placeholder="<?php esc_attr_e('Last name', 'kanguru-support'); ?>"
									autocomplete="family-name" x-model='last_name' />
								<p class="kgr-error" data-field-error-for="last_name" id="error_last_name"></p>
							</div>
						</div>
						<p class="kgr-hint kgr-hidden" data-kgr-qa-hint data-kgr-qa-hint-website>
							<?php esc_html_e('QA Hint: You can enter your own email address here. (the Email set in admin settings will receives admin notifications)', 'kanguru-support'); ?>
						</p>
						<p class="kgr-hint kgr-hidden" data-kgr-qa-hint data-kgr-qa-hint-website>
							<?php esc_html_e('Workflow: if this email matches the WHMCS owner email, confirmation is sent to this address immediately. If it does not match, confirmation is sent to the WHMCS owner email on record.', 'kanguru-support'); ?>
						</p>
						<div class="kgr-actions">
							<button type="button" class="kgr-btn kgr-btn--secondary"
								data-action="back"><img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/back.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--before" /><?php esc_html_e('Back', 'kanguru-support'); ?></button>
							<button type="button" class="kgr-btn kgr-btn--primary" data-action="next">
								<span class="kgr-btn__spinner kgr-hidden" data-next-spinner></span>
								<span data-next-label><?php esc_html_e('Next', 'kanguru-support'); ?></span>
								<img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/next.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--after" />
							</button>
						</div>
					</section>

					<section class="kgr-step" data-step-key="details" aria-hidden="true">
						<div class="kgr-step__head">
							<div class="kgr-progress-wrapper">
								<h3><?php esc_html_e('Tell us what you need', 'kanguru-support'); ?></h3>
								<div class="kgr-progress" aria-live="polite" style="display: none;">
									<span class="kgr-progress__count" data-kgr-step-counter>4/6</span>
								</div>
							</div>
						</div>

						<div class="kgr-branch kgr-hidden" data-branch="details-non-website">
							<div class="kgr-grid kgr-grid--two">
								<div class="kgr-field">
									
									<input id="kgr_business_name" name="organization_name" type="text"
										placeholder="<?php esc_attr_e('Your company name', 'kanguru-support'); ?>"
										x-model="business_name" />
									<p class="kgr-error" data-field-error-for="organization_name"></p>
								</div>
								<div class="kgr-field">
									
								<input id="kgr_non_website_url" name="non_website_url" type="text" inputmode="url"
									placeholder="<?php esc_attr_e('e.g., www.example.com', 'kanguru-support'); ?>" x-model="website_url" />
									<p class="kgr-error" data-field-error-for="non_website_url"></p>
								</div>
							</div>

							<div class="kgr-field">
								
								<input id="kgr_title" name="title" type="text"
									placeholder="<?php esc_attr_e('e.g., Help with account billing', 'kanguru-support'); ?>"
									data-error-key="title" x-model='title' />
								<p class="kgr-error" data-field-error-for="title" id="error_title"></p>
							</div>
							<div class="kgr-field">
								
								<textarea id="kgr_message" name="message"
									placeholder="<?php esc_attr_e('Please describe what you need in detail...', 'kanguru-support'); ?>"
									data-error-key="message" x-model='message'></textarea>
								<p class="kgr-error" data-field-error-for="message" id="error_message"></p>
							</div>
							<div class="kgr-field">
								<div class="kgr-upload">
									<input type="file" id="kgr_non_website_files" accept=".png,.jpg,.jpeg,.webp,.avif,.pdf,.doc,.docx,.xls,.xlsx,.zip" multiple />
									<div class="kgr-previews" data-preview-container="attachments"></div>
								</div>
								<p class="kgr-error" data-field-error-for="attachments"></p>
								<p class="kgr-hint" data-non-website-file-rule></p>
								<p class="kgr-hint kgr-hint--warning kgr-hint--hidden" data-file-reselect-note></p>
							</div>
						</div>

						<div class="kgr-branch kgr-hidden" data-branch="details-website">
							<div class="kgr-issues" data-kgr-issues></div>
							<div class="kgr-actions kgr-actions--left">
								<button type="button" class="kgr-outline-btn" data-action="add-issue">
									+ <?php esc_html_e('Add another issue', 'kanguru-support'); ?>
								</button>
							</div>
						</div>

						<div class="kgr-actions">
							<button type="button" class="kgr-btn kgr-btn--secondary"
								data-action="back"><img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/back.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--before" /><?php esc_html_e('Back', 'kanguru-support'); ?></button>
							<button type="button" class="kgr-btn kgr-btn--primary" data-action="next">
								<span class="kgr-btn__spinner kgr-hidden" data-next-spinner></span>
								<span data-next-label> <?php esc_html_e('Next', 'kanguru-support'); ?></span>
								<img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/next.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--after" />
							</button>
						</div>
					</section>

					<section class="kgr-step" data-step-key="review" aria-hidden="true">
						<div class="kgr-step__head">
							<div class="kgr-progress-wrapper">
								<h3><?php esc_html_e('Review your request', 'kanguru-support'); ?></h3>
								<div class="kgr-progress" aria-live="polite" style="display: none;">
									<span class="kgr-progress__count" data-kgr-step-counter>5/6</span>
								</div>
							</div>
							<p><?php esc_html_e('Please confirm everything before submitting.', 'kanguru-support'); ?>
							</p>
						</div>
						<div class="kgr-review" data-kgr-review></div>
						<div class="kgr-actions">
							<button type="button" class="kgr-btn kgr-btn--secondary"
								data-action="back"><img src="<?php echo esc_url(KGR_PLUGIN_URL . 'assets/img/back.svg'); ?>" alt="" aria-hidden="true" class="kgr-btn__icon kgr-btn__icon--before" /><?php esc_html_e('Back', 'kanguru-support'); ?></button>
							<button type="submit" class="kgr-btn kgr-btn--primary" data-action="submit">
								<span class="kgr-btn__spinner kgr-hidden" data-submit-spinner></span>
								<span
									data-submit-label><?php esc_html_e('Submit Request', 'kanguru-support'); ?></span>
							</button>
						</div>
					</section>

					<section class="kgr-step" data-step-key="submit-state" aria-hidden="true">
						<div class="kgr-submit-state" data-kgr-submit-state>
							<div class="kgr-submit-state__icon" data-submit-state-icon aria-hidden="true">⏳</div>
							<h3 data-submit-state-title>
								<?php esc_html_e('Submitting your request...', 'kanguru-support'); ?>
							</h3>
							<p data-submit-state-message>
								<?php esc_html_e('Please wait while we send your request.', 'kanguru-support'); ?>
							</p>
						</div>
						<div class="kgr-actions kgr-actions--center">
							<button type="button" class="kgr-btn kgr-btn--secondary kgr-hidden"
								data-action="back-to-review"><?php esc_html_e('Back to review', 'kanguru-support'); ?></button>
						</div>
					</section>
				</form>
			</div>

		</div>

		<template id="kgr-issue-template">
			<div class="kgr-issue-card" data-issue-card>
				<div class="kgr-issue-card__head">
					<h4 class="kgr-issue-card__title" data-issue-title>
						<?php esc_html_e('Issue', 'kanguru-support'); ?>
					</h4>
					<button type="button" class="kgr-link-btn kgr-link-btn--danger kgr-hidden"
						data-action="remove-issue">
						<?php esc_html_e('Remove', 'kanguru-support'); ?>
					</button>
				</div>

				<div class="kgr-field">
					<input type="text" data-issue-field="page_url"
						placeholder="<?php esc_attr_e('Which page is this on? e.g., /contact-us or homepage', 'kanguru-support'); ?>" />
					<p class="kgr-error" data-issue-error="page_url"></p>
					<p class="kgr-hint kgr-hidden" data-kgr-qa-hint>
						<?php esc_html_e('QA Hint: as a website you can use', 'kanguru-support'); ?>
						<button type="button" class="kgr-qa-copy" data-kgr-copy-text="www.test-support.com/contact-us" data-kgr-copy-label="<?php esc_attr_e('Copied', 'kanguru-support'); ?>">
							<strong>www.test-support.com/contact-us</strong>
							<span class="kgr-qa-copy__toast" aria-live="polite" aria-hidden="true"><?php esc_html_e('Copied', 'kanguru-support'); ?></span>
						</button>
					</p>
				</div>

				<div class="kgr-grid kgr-grid--two">
					<div class="kgr-field">
						<select data-issue-field="issue_type">
							<option value=""><?php esc_html_e('What type of issue?', 'kanguru-support'); ?></option>
							<option value="Content change">
								<?php esc_html_e('Content change', 'kanguru-support'); ?>
							</option>
							<option value="Image replacement">
								<?php esc_html_e('Image replacement', 'kanguru-support'); ?>
							</option>
							<option value="Form problem">
								<?php esc_html_e('Form problem', 'kanguru-support'); ?>
							</option>
							<option value="Performance issue">
								<?php esc_html_e('Performance issue', 'kanguru-support'); ?>
							</option>
							<option value="Other"><?php esc_html_e('Other', 'kanguru-support'); ?></option>
						</select>
						<p class="kgr-error" data-issue-error="issue_type"></p>
					</div>
					<div class="kgr-field">
						<select data-issue-field="urgency">
							<option value=""><?php esc_html_e('How urgent is this?', 'kanguru-support'); ?>
							</option>
							<option value="Low"><?php esc_html_e('Low', 'kanguru-support'); ?>
							</option>
							<option value="Medium">
								<?php esc_html_e('Medium', 'kanguru-support'); ?>
							</option>
							<option value="High"><?php esc_html_e('High', 'kanguru-support'); ?>
							</option>
						</select>
						<p class="kgr-error" data-issue-error="urgency"></p>
					</div>
				</div>

				<div class="kgr-field kgr-hidden" data-issue-conditional="content_change">
					<textarea data-issue-field="change_details"
						placeholder="<?php esc_attr_e('What needs to be changed? e.g., Change the phone number in the footer to...', 'kanguru-support'); ?>"></textarea>
					<p class="kgr-error" data-issue-error="change_details"></p>
				</div>

				<div class="kgr-field kgr-hidden" data-issue-conditional="image_replacement">
					<textarea data-issue-field="image_details"
						placeholder="<?php esc_attr_e('Which image should we replace? e.g., The main banner image on the homepage...', 'kanguru-support'); ?>"></textarea>
					<p class="kgr-error" data-issue-error="image_details"></p>

					<div class="kgr-field-sub">
						<div class="kgr-upload">
							<input type="file" data-issue-field="screenshots" accept=".png,.jpg,.jpeg,.webp,.avif,.zip"
								multiple />
							<div class="kgr-previews" data-preview-container="issue-files"></div>
							<span class="kgr-hint">
								<?php esc_html_e('Accepted: .png, .jpg, .jpeg, .webp, .avif, .zip.', 'kanguru-support'); ?>
							</span>
						</div>
						<p class="kgr-error" data-issue-error="screenshots"></p>
					</div>
				</div>

				<div class="kgr-field" data-issue-conditional="description">
					<textarea data-issue-field="description"
						placeholder="<?php esc_attr_e('Tell us more about the problem e.g., When I click the button, nothing happens...', 'kanguru-support'); ?>"></textarea>
					<p class="kgr-error" data-issue-error="description"></p>
				</div>

				<div class="kgr-field" data-issue-conditional="screenshots">
					<div class="kgr-upload">
						<input type="file" data-issue-field="screenshots" accept=".png,.jpg,.jpeg,.webp,.avif"
							multiple />
						<div class="kgr-previews" data-preview-container="issue-files"></div>
						<span class="kgr-hint" data-screenshot-rule></span>
					</div>
					<p class="kgr-error" data-issue-error="screenshots"></p>
					<p class="kgr-hint kgr-hint--warning kgr-hint--hidden" data-file-reselect-note></p>
				</div>
			</div>
		</template>
	</div>
</section>

