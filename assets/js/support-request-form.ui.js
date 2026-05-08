/* global KGR_CONFIG */
( function () {
	'use strict';

	const ISSUE_TYPES = window.KGR_ISSUE_TYPES || {
		CONTENT: 'Content change',
		IMAGE: 'Image replacement',
		FORM: 'Form problem',
		PERFORMANCE: 'Performance issue',
		OTHER: 'Other',
	};

	class SupportFormUI {
		constructor( root, config ) {
			this.root = root;
			this.config = config || {};
			this.form = root.querySelector( '[data-kgr-form]' );
			this.alert = root.querySelector( '[data-kgr-alert]' );
			this.stepCounters = root.querySelectorAll( '[data-kgr-step-counter]' );
			this.stepLabels = root.querySelectorAll( '[data-kgr-step-label]' );
			this.layout = root.querySelector( '.kgr-layout' );
			this.previewContainer = root.querySelector( '[data-kgr-preview-content]' );
			this.reviewContainer = root.querySelector( '[data-kgr-review]' );
			this.issueTemplate = root.querySelector( '#kgr-issue-template' ) || document.getElementById( 'kgr-issue-template' );
			this.issuesContainer = root.querySelector( '[data-kgr-issues]' );
			this.submitLabel = root.querySelector( '[data-submit-label]' );
			this.submitSpinner = root.querySelector( '[data-submit-spinner]' );
			this.submitStateTitle = root.querySelector( '[data-submit-state-title]' );
			this.submitStateMessage = root.querySelector( '[data-submit-state-message]' );
			this.submitStateIcon = root.querySelector( '[data-submit-state-icon]' );
			this.backToReviewBtn = root.querySelector( '[data-action="back-to-review"]' );

			this.steps = [ 'service', 'validation', 'contact', 'details', 'review', 'submit-state' ];
			this.currentStep = 'service';
			this.submitting = false;

			this.state = {
				service_type: '',
				website_url: '',
				email: '',
				first_name: '',
				last_name: '',
				business_name: '',
				title: '',
				message: '',
				attachments: [],
				issues: [],
				step_index: 0,
				form_started_at: Math.floor( Date.now() / 1000 ),
			};

			this.turnstileWidgetId = null;
			this.validationCache = new Map();
			this.isInitialized = false;
		}


		init() {
			const doInit = () => {
				if ( ! this.root ) {
					return;
				}
				if ( this.isInitialized ) {
					return;
				}

				// Try to get Alpine data
				if ( window.Alpine ) {
					try {
						// Help Alpine find the data if it's struggling
						const el = this.root;
						const alpineState = window.Alpine.$data( el );
						
						if ( alpineState ) {
							console.log( '[KGR] Alpine state found, linking...' );
							// Sync our initial state into Alpine
							Object.assign( alpineState, this.state );
							// Link our state to Alpine's proxy
							this.state = alpineState;
						} else {
							console.warn( '[KGR] Alpine state not found on root element' );
						}
					} catch ( e ) {
						console.error( '[KGR] Alpine data sync failed', e );
					}
				}

				if ( ! this.form ) {
					return;
				}
				this.isInitialized = true;
				
				if ( this.issuesContainer && this.state.issues && this.state.issues.length === 0 ) {
					this.addIssue();
				}
				
				this.bindEvents();
				this.applyBranchState();
				this.syncProgress();
				this.updateLayoutMode();
				this.renderPreview();
				this.renderReview();
				this.setHelperText();
				this.applyQaHintsVisibility();
				this.initQaHintComponents();
				this.initCharCounters();
			};

			if ( document.readyState === 'complete' || document.readyState === 'interactive' ) {
				// If Alpine is already there, wait a tiny bit for it to finish its internal init
				if ( window.Alpine && window.Alpine.initialized ) {
					setTimeout( doInit, 20 );
				} else {
					document.addEventListener( 'alpine:init', doInit );
					// Fallback
					setTimeout( doInit, 1000 );
				}
			} else {
				document.addEventListener( 'DOMContentLoaded', () => {
					if ( window.Alpine ) {
						doInit();
					} else {
						document.addEventListener( 'alpine:init', doInit );
					}
				} );
			}
		}

		bindEvents() {
			this.form.addEventListener( 'click', ( event ) => {
				const action = event.target.closest( '[data-action]' );
				if ( ! action ) {
					return;
				}
				const key = action.getAttribute( 'data-action' );
				if ( key === 'next' ) {
					this.handleNext( action );
				}
				if ( key === 'back' ) {
					this.handleBack();
				}
				if ( key === 'add-issue' ) {
					this.addIssue();
				}
				if ( key === 'remove-issue' ) {
					const card = action.closest( '[data-issue-card]' );
					this.removeIssue( parseInt( card.dataset.issueIndex, 10 ) );
				}
				if ( key === 'back-to-review' ) {
					this.switchStep( 'review' );
				}
			} );

			this.form.addEventListener( 'input', ( event ) => this.handleInputChange( event.target ) );
			this.form.addEventListener( 'change', ( event ) => this.handleInputChange( event.target ) );
			this.form.addEventListener( 'submit', ( event ) => this.handleSubmit( event ) );
		}

		handleInputChange( input ) {
			const name = input.name || '';
			if ( name === 'service_type' ) {
				this.state.service_type = input.value;
				this.applyBranchState();
				this.updateLayoutMode();
			}
			if ( name === 'website_url' ) {
				this.state.website_url = input.value.trim();
			}
			if ( name === 'email' ) {
				this.state.email = input.value.trim();
			}
			if ( name === 'first_name' ) {
				this.state.first_name = input.value.trim();
			}
			if ( name === 'last_name' ) {
				this.state.last_name = input.value.trim();
			}
			if ( name === 'organization_name' ) {
				this.state.business_name = input.value.trim();
			}
			if ( name === 'title' ) {
				this.state.title = input.value.trim();
			}
			if ( name === 'message' ) {
				this.state.message = input.value.trim();
			}
			if ( input.id === 'KGR_non_website_files' ) {
				this.state.attachments = Array.from( input.files || [] );
				this.renderFileList( input.closest( '.kgr-upload' ), this.state.attachments, 'No files selected yet.', input );
			}

			if ( input.hasAttribute( 'data-issue-field' ) ) {
				const card = input.closest( '[data-issue-card]' );
				const index = parseInt( card.dataset.issueIndex, 10 );
				const field = input.getAttribute( 'data-issue-field' );
				if ( field === 'screenshots' ) {
					this.state.issues[ index ][ field ] = Array.from( input.files || [] );
					this.renderFileList( input.closest( '.kgr-upload' ), this.state.issues[ index ].screenshots, 'No screenshots selected yet.', input );
				} else {
					this.state.issues[ index ][ field ] = input.value.trim();
					if ( field === 'issue_type' ) {
						this.toggleIssueConditional( card, this.state.issues[ index ] );
					}
				}
			}

			this.clearErrorByInput( input );
			this.clearAlert();
			if ( input.name === 'website_url' ) {
				this.resetValidationActions();
			}
			this.renderPreview();
			this.renderReview();
		}

		async handleNext( actionEl = null ) {
			this.clearAlert();
			this.clearErrors();

			let loadingTimer = null;
			if ( actionEl && this.currentStep === 'validation' && this.state.service_type === 'Website' ) {
				loadingTimer = window.setTimeout( () => this.setNextButtonLoading( actionEl, true ), 300 );
			}

			const valid = await this.validateStep( this.currentStep );

			if ( loadingTimer ) {
				window.clearTimeout( loadingTimer );
			}
			if ( actionEl ) {
				this.setNextButtonLoading( actionEl, false );
			}

			if ( ! valid ) {
				return;
			}

			const index = this.steps.indexOf( this.currentStep );
			let nextIndex = index + 1;
			
			// Skip Step 2 (validation) for non-website
			if ( this.steps[ nextIndex ] === 'validation' && this.state.service_type !== 'Website' ) {
				nextIndex++;
			}

			const nextStep = this.steps[ nextIndex ];
			if ( ! nextStep || nextStep === 'submit-state' ) {
				return;
			}
			this.switchStep( nextStep );
		}

		handleBack() {
			this.clearAlert();
			const index = this.steps.indexOf( this.currentStep );
			let prevIndex = index - 1;

			// Skip Step 2 (validation) for non-website
			if ( this.steps[ prevIndex ] === 'validation' && this.state.service_type !== 'Website' ) {
				prevIndex--;
			}

			const previous = this.steps[ prevIndex ];
			if ( previous ) {
				this.switchStep( previous );
			}
		}

		setNextButtonLoading( actionEl, loading ) {
			const button = actionEl && actionEl.matches( '[data-action="next"]' ) ? actionEl : null;
			if ( ! button ) {
				return;
			}
			const spinner = button.querySelector( '[data-next-spinner]' );
			const label = button.querySelector( '[data-next-label]' );
			
			button.disabled = !!loading;
			
			if ( spinner ) {
				spinner.classList.toggle( 'kgr-hidden', ! loading );
			}
			if ( label ) {
				label.classList.toggle( 'kgr-hidden', !! loading );
			}
		}

		async handleSubmit( event ) {
			event.preventDefault();
			if ( this.submitting ) {
				return;
			}

			this.clearErrors();
			this.clearAlert();

			let loadingTimer = window.setTimeout( () => this.setSubmittingState( true ), 300 );

			const stepOrder = [ 'service', 'validation', 'contact', 'details', 'review' ];
			let isValid = true;
			for ( const step of stepOrder ) {
				const ok = await this.validateStep( step );
				if ( ! ok ) {
					this.switchStep( step );
					isValid = false;
					break;
				}
			}

			if ( loadingTimer ) {
				window.clearTimeout( loadingTimer );
			}

			if ( ! isValid ) {
				this.setSubmittingState( false );
				return;
			}

			this.setSubmittingState( true );
			this.switchStep( 'submit-state' );
			this.setSubmitState( 'pending' );

			try {
				const payload = await this.buildPayload();
				console.log( '[KGR] Submitting payload:', Object.fromEntries( payload.entries() ) );
				
				const response = await this.request( this.config.submitRequestEndpoint, {
					method: 'POST',
					body: payload,
				} );

				if ( response.success ) {
					this.setSubmitState( 'success', response.message || 'Request sent successfully. Please check your email for confirmation.' );
					return;
				}

				this.setSubmitState( 'error', response.message || this.config.i18n.genericError );
				this.showAlert( 'error', response.message || this.config.i18n.genericError );
				if ( response.errors ) {
					this.applyBackendErrors( response.errors );
					this.showFileReselectHints();
				}
			} catch ( error ) {
				this.setSubmitState( 'error', this.config.i18n.genericError );
				this.showAlert( 'error', this.config.i18n.genericError );
				this.showFileReselectHints();
			} finally {
				this.setSubmittingState( false );
			}
		}

		async validateStep( step ) {
			if ( step === 'service' ) {
				return this.validateService();
			}
			if ( step === 'validation' ) {
				return this.validateServicePath();
			}
			if ( step === 'contact' ) {
				return this.validateContact();
			}
			if ( step === 'details' ) {
				return this.state.service_type === 'Website' ? this.validateIssues() : this.validateNonWebsiteDetails();
			}
			return true;
		}

		validateService() {
			if ( this.state.service_type ) {
				return true;
			}
			this.setFieldError( 'service_type', 'Please select your service.' );
			return false;
		}

		async validateServicePath() {
			if ( this.state.service_type !== 'Website' ) {
				return true;
			}
			if ( ! this.state.website_url ) {
				this.setFieldError( 'website_url', 'Website URL is required.' );
				return false;
			}
			if ( ! this.isValidWebsiteInput( this.state.website_url ) ) {
				this.setFieldError( 'website_url', 'Please enter a valid website URL or domain.' );
				return false;
			}

			this.resetValidationActions();
			const normalizedWebsite = this.normalizeUrlInput( this.state.website_url );
			const cacheKey = normalizedWebsite.toLowerCase();
			const cached = this.validationCache.get( cacheKey );
			if ( cached ) {
				if ( cached.success ) {
					return true;
				}
				const cachedMsg = cached.message || this.config.i18n.websiteNotFound;
				this.setFieldError( 'website_url', cachedMsg );
				this.showAlert( 'error', cachedMsg );
				this.handleWebsiteNotFound( cachedMsg );
				return false;
			}

			try {
				const response = await this.request( this.config.validateWebsiteEndpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { website_url: this.state.website_url, service_type: this.state.service_type } ),
					timeoutMs: this.config.validateRequestTimeoutMs || 12000,
				} );
				if ( response.success ) {
					this.validationCache.set( cacheKey, { success: true, message: '' } );
					return true;
				}
				const msg = response.message || this.config.i18n.websiteNotFound;
				this.validationCache.set( cacheKey, { success: false, message: msg } );
				this.setFieldError( 'website_url', msg );
				this.showAlert( 'error', msg );
				this.handleWebsiteNotFound( msg );
				return false;
			} catch ( error ) {
				const msg = error.message || this.config.i18n.genericError;
				this.validationCache.set( cacheKey, { success: false, message: msg } );
				this.setFieldError( 'website_url', msg );
				this.showAlert( 'error', msg );
				this.handleWebsiteNotFound( msg );
				return false;
			}
		}

		handleWebsiteNotFound( message ) {
			const triggerText = "couldn't find your website";
			if ( ! message.toLowerCase().includes( triggerText.toLowerCase() ) ) {
				return;
			}

			const step = this.form.querySelector( '[data-step-key="validation"]' );
			if ( ! step ) {
				return;
			}

			const backBtn = step.querySelector( '[data-action="back"]' );
			const nextBtn = step.querySelector( '[data-action="next"]' );
			const contactBtn = step.querySelector( '[data-kgr-contact-btn]' );

			if ( backBtn ) {
				backBtn.classList.add( 'kgr-hidden' );
			}
			if ( nextBtn ) {
				nextBtn.classList.add( 'kgr-hidden' );
			}
			if ( contactBtn ) {
				contactBtn.classList.remove( 'kgr-hidden' );
			}
		}

		resetValidationActions() {
			const step = this.form.querySelector( '[data-step-key="validation"]' );
			if ( ! step ) {
				return;
			}
			const backBtn = step.querySelector( '[data-action="back"]' );
			const nextBtn = step.querySelector( '[data-action="next"]' );
			const contactBtn = step.querySelector( '[data-kgr-contact-btn]' );

			if ( backBtn ) {
				backBtn.classList.remove( 'kgr-hidden' );
			}
			if ( nextBtn ) {
				nextBtn.classList.remove( 'kgr-hidden' );
			}
			if ( contactBtn ) {
				contactBtn.classList.add( 'kgr-hidden' );
			}
		}

		validateContact() {
			let valid = true;
			if ( ! this.state.email ) {
				this.setFieldError( 'email', 'Email is required.' );
				valid = false;
			} else if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( this.state.email ) ) {
				this.setFieldError( 'email', 'Please enter a valid email address.' );
				valid = false;
			}
			if ( ! this.state.first_name ) {
				this.setFieldError( 'first_name', 'First name is required.' );
				valid = false;
			}
			if ( ! this.state.last_name ) {
				this.setFieldError( 'last_name', 'Last name is required.' );
				valid = false;
			}
			return valid;
		}

		validateNonWebsiteDetails() {
			let valid = true;
			if ( ! this.state.title ) {
				this.setFieldError( 'title', 'Title is required.' );
				valid = false;
			} else if ( this.state.title.length > 255 ) {
				this.setFieldError( 'title', 'Title must be under 255 characters.' );
				valid = false;
			}

			if ( this.state.business_name && this.state.business_name.length > 255 ) {
				this.setFieldError( 'organization_name', 'Business name must be under 255 characters.' );
				valid = false;
			}

			if ( ! this.state.message || this.state.message.length < 20 ) {
				this.setFieldError( 'message', this.config.i18n.descriptionMinMessage || 'Please enter at least 20 characters.' );
				valid = false;
			}
			if ( this.state.attachments.length ) {
				const maxTotal = ( this.config.maxNonWebsiteUploadMb || 10 ) * 1024 * 1024;
				const total = this.state.attachments.reduce( ( acc, file ) => acc + file.size, 0 );
				const nonWebsiteAllowedExt = [ 'png', 'jpg', 'jpeg', 'webp', 'avif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip' ];
				const invalidAttachment = this.state.attachments.find( ( file ) => ! this.fileMatchesExtensions( file, nonWebsiteAllowedExt ) );
				if ( invalidAttachment ) {
					this.setFieldError( 'attachments', 'Accepted file types: images, PDF, Word, Excel, ZIP.' );
					valid = false;
				}
				if ( total > maxTotal ) {
					this.setFieldError( 'attachments', `Total attachment size must be under ${ this.config.maxNonWebsiteUploadMb || 10 }MB.` );
					valid = false;
				}
			}
			return valid;
		}

		validateIssues() {
			let valid = true;
			if ( ! this.state.issues.length ) {
				this.showAlert( 'error', 'Please add at least one issue.' );
				return false;
			}

			const maxScreenshots = this.config.maxIssueScreenshots || 2;
			const maxScreenshotSize = ( this.config.maxScreenshotMb || 1 ) * 1024 * 1024;

			this.state.issues.forEach( ( issue, index ) => {
				console.log( `[KGR] Validating issue ${ index }`, issue );
				const normalizedPageUrl = this.normalizeUrlInput( issue.page_url );
				if ( ! normalizedPageUrl ) {
					this.setIssueError( index, 'page_url', 'Please add a valid page URL.' );
					valid = false;
				} else {
					issue.page_url = normalizedPageUrl;
					const card = this.issuesContainer.querySelector( `[data-issue-index="${ index }"]` );
					if ( card ) {
						const input = card.querySelector( '[data-issue-field="page_url"]' );
						if ( input ) {
							input.value = normalizedPageUrl;
						}
					}
				}
				if ( ! issue.issue_type ) {
					this.setIssueError( index, 'issue_type', 'Issue type is required.' );
					valid = false;
				}
				if ( ! issue.urgency ) {
					this.setIssueError( index, 'urgency', 'Urgency is required.' );
					valid = false;
				}
				if ( issue.issue_type !== ISSUE_TYPES.CONTENT && issue.issue_type !== ISSUE_TYPES.IMAGE ) {
					if ( ! issue.description || issue.description.length < 20 ) {
						this.setIssueError( index, 'description', this.config.i18n.descriptionMinMessage || 'Please enter at least 20 characters.' );
						valid = false;
					}
				}
				if ( issue.issue_type === ISSUE_TYPES.CONTENT ) {
					if ( ! issue.change_details || issue.change_details.length < 20 ) {
						this.setIssueError( index, 'change_details', 'Please describe the change needed (minimum 20 characters).' );
						valid = false;
					}
				}
				if ( issue.issue_type === ISSUE_TYPES.IMAGE ) {
					if ( ! issue.image_details || issue.image_details.length < 20 ) {
						this.setIssueError( index, 'image_details', 'Please describe which image should be replaced (minimum 20 characters).' );
						valid = false;
					}
					if ( (issue.screenshots || []).length === 0 ) {
						this.setIssueError( index, 'screenshots', 'Please upload the replacement image(s) or a ZIP archive.' );
						valid = false;
					}
				}

				const shots = issue.screenshots || [];
				if ( shots.length > maxScreenshots ) {
					this.setIssueError( index, 'screenshots', `Maximum ${ maxScreenshots } screenshots are allowed.` );
					valid = false;
				}
				const invalidType = shots.find( ( file ) => {
					if ( issue.issue_type === ISSUE_TYPES.IMAGE ) {
						return ! this.fileMatchesExtensions( file, [ 'png', 'jpg', 'jpeg', 'webp', 'avif', 'zip' ] );
					}
					if ( issue.issue_type === ISSUE_TYPES.CONTENT ) {
						return ! this.fileMatchesExtensions( file, [ 'png', 'jpg', 'jpeg', 'webp', 'avif', 'pdf', 'doc', 'docx', 'zip' ] );
					}
					return ! file.type.startsWith( 'image/' );
				} );
				const invalidSize = shots.find( ( file ) => file.size > maxScreenshotSize );
				if ( invalidType ) {
					let errorMsg = 'Screenshots must be image files.';
					if ( issue.issue_type === ISSUE_TYPES.IMAGE ) errorMsg = 'Accepted files: Images or ZIP';
					if ( issue.issue_type === ISSUE_TYPES.CONTENT ) errorMsg = 'Accepted files: Images, PDF, Word, ZIP';
					this.setIssueError( index, 'screenshots', errorMsg );
					valid = false;
				}
				if ( invalidSize ) {
					this.setIssueError( index, 'screenshots', `Each screenshot must be under ${ this.config.maxScreenshotMb || 1 }MB.` );
					valid = false;
				}
			} );

			console.log( '[KGR] Validation result:', valid );
			return valid;
		}

		fileMatchesExtensions( file, allowedExtensions ) {
			if ( ! file || ! file.name ) {
				return false;
			}
			const ext = file.name.split( '.' ).pop().toLowerCase();
			return allowedExtensions.includes( ext );
		}

		switchStep( key ) {
			if ( key === this.currentStep ) {
				return;
			}
			const current = this.form.querySelector( `[data-step-key="${ this.currentStep }"]` );
			const next = this.form.querySelector( `[data-step-key="${ key }"]` );
			if ( ! current || ! next ) {
				return;
			}

			current.classList.remove( 'is-active' );
			current.setAttribute( 'aria-hidden', 'true' );
			
			next.classList.add( 'is-active' );
			next.setAttribute( 'aria-hidden', 'false' );

			// Reset buttons if leaving validation step
			if ( this.currentStep === 'validation' ) {
				this.resetValidationActions();
			}

			this.currentStep = key;
			this.state.step_index = this.steps.indexOf( key );
			this.syncProgress();
			this.updateLayoutMode();
			this.renderPreview();
			this.renderReview();
		}

		syncProgress() {
			const isWebsite = this.state.service_type === 'Website';
			const visibleSteps = isWebsite ? this.steps : this.steps.filter( s => s !== 'validation' );
			const total = visibleSteps.length - 1; // Exclude submit-state
			
			const currentIndex = visibleSteps.indexOf( this.currentStep ) + 1;
			const isFirstStep = currentIndex === 1;

			this.stepCounters.forEach( ( el ) => {
				el.textContent = `${ currentIndex }/${ total }`;
				const wrap = el.closest( '.kgr-progress' );
				if ( wrap ) {
					wrap.style.display = isFirstStep ? 'none' : '';
				} else {
					el.style.display = isFirstStep ? 'none' : '';
				}
			} );
			this.stepLabels.forEach( ( el ) => {
				el.textContent = `Step ${ currentIndex } of ${ total }`;
				const wrap = el.closest( '.kgr-progress' );
				if ( wrap ) {
					wrap.style.display = isFirstStep ? 'none' : '';
				} else {
					el.style.display = isFirstStep ? 'none' : '';
				}
			} );
		}

		updateLayoutMode() {
			if ( ! this.layout ) {
				return;
			}
			const serviceOnlyMode = false; // Always show preview/split mode
			const hidePreviewMode = this.currentStep === 'review' || this.currentStep === 'submit-state';
			
			this.layout.classList.toggle( 'kgr-mode--service', serviceOnlyMode );
			this.layout.classList.toggle( 'kgr-mode--split', ! hidePreviewMode );
			this.layout.classList.toggle( 'kgr-mode--full', hidePreviewMode );
		}

		applyBranchState() {
			const isWebsite = this.state.service_type === 'Website';
			const map = {
				'website-validation': isWebsite,
				'non-website-routing': ! isWebsite,
				'details-website': isWebsite,
				'details-non-website': ! isWebsite,
			};

			Object.keys( map ).forEach( ( branch ) => {
				const el = this.root.querySelector( `[data-branch="${ branch }"]` );
				if ( el ) {
					el.classList.toggle( 'kgr-hidden', ! map[ branch ] );
				}
			} );
		}

		addIssue() {
			if ( ! this.issueTemplate ) {
				this.issueTemplate = this.root.querySelector( '#kgr-issue-template' ) || document.getElementById( 'kgr-issue-template' );
			}
			if ( ! this.issueTemplate ) {
				return;
			}
			const data = {
				page_url: '',
				issue_type: '',
				urgency: 'Medium',
				description: '',
				change_details: '',
				image_details: '',
				screenshots: [],
			};
			this.state.issues.push( data );

			const fragment = this.issueTemplate.content.cloneNode( true );
			const card = fragment.querySelector( '[data-issue-card]' );
			const index = this.state.issues.length - 1;
			card.dataset.issueIndex = String( index );
			this.bindIssueCard( card, index );
			if ( this.issuesContainer ) {
				this.issuesContainer.appendChild( card );
			}
			this.renderPreview();
			this.renderReview();
		}

		removeIssue( index ) {
			if ( this.state.issues.length <= 1 ) {
				return;
			}
			this.state.issues.splice( index, 1 );
			const card = this.issuesContainer.querySelector( `[data-issue-index="${ index }"]` );
			if ( card ) {
				card.remove();
			}
			this.reindexIssueCards();
			this.renderPreview();
			this.renderReview();
		}

		reindexIssueCards() {
			if ( ! this.issuesContainer ) {
				return;
			}
			const cards = this.issuesContainer.querySelectorAll( '[data-issue-card]' );
			cards.forEach( ( card, index ) => {
				card.dataset.issueIndex = String( index );
				this.bindIssueCard( card, index );
				const remove = card.querySelector( '[data-action="remove-issue"]' );
				if ( remove ) {
					remove.classList.toggle( 'kgr-hidden', this.state.issues.length <= 1 );
				}
			} );
		}

		bindIssueCard( card, index ) {
			const issue = this.state.issues[ index ];
			card.querySelector( '[data-issue-title]' ).textContent = `Issue ${ index + 1 }`;

			card.querySelectorAll( '[data-issue-field]' ).forEach( ( input ) => {
				const field = input.getAttribute( 'data-issue-field' );
				const id = `KGR_issue_${ index }_${ field }`;
				input.id = id;
				input.name = `issues[${ index }][${ field }]`;
				if ( input.type !== 'file' ) {
					input.value = issue[ field ] || '';
				}
				const label = input.closest( '.kgr-field' ).querySelector( 'label' );
				if ( label ) {
					label.setAttribute( 'for', id );
				}
				const errorNode = card.querySelector( `[data-issue-error="${ field }"]` );
				if ( errorNode ) {
					errorNode.id = `error_issue_${ index }_${ field }`;
				}
				if ( input.tagName === 'TEXTAREA' ) {
					this.setupCharCounter( input );
				}
			} );

			this.toggleIssueConditional( card, issue );
			const remove = card.querySelector( '[data-action="remove-issue"]' );
			remove.classList.toggle( 'kgr-hidden', this.state.issues.length <= 1 );
			this.setHelperText( card );
		}

		toggleIssueConditional( card, issue ) {
			const content = card.querySelector( '[data-issue-conditional="content_change"]' );
			const image = card.querySelector( '[data-issue-conditional="image_replacement"]' );
			const description = card.querySelector( '[data-issue-conditional="description"]' );
			const screenshots = card.querySelector( '[data-issue-conditional="screenshots"]' );

			const isContent = issue.issue_type === ISSUE_TYPES.CONTENT;
			const isImage = issue.issue_type === ISSUE_TYPES.IMAGE;

			if ( content ) content.classList.toggle( 'kgr-hidden', ! isContent );
			if ( image ) image.classList.toggle( 'kgr-hidden', ! isImage );
			if ( description ) description.classList.toggle( 'kgr-hidden', isContent || isImage );
			if ( screenshots ) {
				screenshots.classList.toggle( 'kgr-hidden', isImage );
				const label = screenshots.querySelector( 'label' );
				if ( label ) {
					label.textContent = isContent ? 'Upload files (optional)' : 'Upload screenshots (optional)';
				}
				const fileInput = screenshots.querySelector( 'input[type="file"]' );
				if ( fileInput ) {
					fileInput.setAttribute( 'accept', isContent ? '.png,.jpg,.jpeg,.webp,.avif,.pdf,.doc,.docx,.zip' : '.png,.jpg,.jpeg,.webp,.avif' );
				}
				const hint = screenshots.querySelector( '.kgr-hint:not([data-file-reselect-note])' );
				if ( hint ) {
					hint.textContent = isContent ? 'Accepted: Images, PDF, Word, ZIP.' : '';
				}
			}
		}

		async buildPayload() {
			const formData = new FormData();
			
			// 1. Get raw data from state to bypass Alpine's Proxy system
			const rawState = window.Alpine ? window.Alpine.raw( this.state ) : this.state;
			
			// 2. Map known top-level fields to EXACT keys the backend expects
			formData.append( 'email', String( rawState.email || '' ).trim() );
			formData.append( 'first_name', String( rawState.first_name || '' ).trim() );
			formData.append( 'last_name', String( rawState.last_name || '' ).trim() );
			formData.append( 'service_type', String( rawState.service_type || '' ) );
			formData.append( 'client_company', String( rawState.business_name || '' ).trim().substring(0, 255) );
			
			// Backend uses 'selected_domain' as primary key for website domain
			formData.append( 'selected_domain', String( rawState.website_url || '' ).trim() );
			formData.append( 'website_url', String( rawState.website_url || '' ).trim() );
			
			formData.append( 'message', String( rawState.message || '' ).trim() );
			
			// Backend expects 'title'
			const title = rawState.title || rawState.message || (rawState.service_type + ' Support Request');
			formData.append( 'title', String( title ).trim() );

			// 3. Handle Issues (Website mode) or Attachments (Service mode)
			if ( rawState.service_type === 'Website' ) {
				( rawState.issues || [] ).forEach( ( issue, i ) => {
					formData.append( `issues[${ i }][page_url]`, String( issue.page_url || '' ).trim() );
					formData.append( `issues[${ i }][issue_type]`, String( issue.issue_type || '' ) );
					
					// IMPORTANT: Map UI urgency labels to Backend urgency levels
					let urgencyLevel = 'Minor issue';
					if ( issue.urgency === 'Medium' ) {
						urgencyLevel = 'Some users affected';
					} else if ( issue.urgency === 'High' ) {
						urgencyLevel = 'Website unusable';
					}
					formData.append( `issues[${ i }][urgency_level]`, urgencyLevel );
					
					formData.append( `issues[${ i }][description]`, String( issue.description || '' ).trim() );
					formData.append( `issues[${ i }][change_details]`, String( issue.change_details || '' ).trim() );
					formData.append( `issues[${ i }][image_details]`, String( issue.image_details || '' ).trim() );
					
					if ( Array.isArray( issue.screenshots ) ) {
						issue.screenshots.forEach( ( file ) => formData.append( `issues[${ i }][screenshots][]`, file ) );
					}
				} );
			} else {
				( rawState.attachments || [] ).forEach( ( file ) => formData.append( 'attachments[]', file ) );
			}

			if ( window.KGRFormSecurity && typeof window.KGRFormSecurity.appendSecurityPayload === 'function' ) {
				await window.KGRFormSecurity.appendSecurityPayload( this.form, this.config, rawState, this, formData );
			}

			return formData;
		}

		async request( url, options ) {
			if ( ! url || url === '#' ) {
				throw new Error( 'Configuration error: Invalid API endpoint.' );
			}
			const timeout = options && options.timeoutMs ? options.timeoutMs : ( this.config.requestTimeoutMs || 25000 );
			const controller = new AbortController();
			const timer = window.setTimeout( () => controller.abort(), timeout );
			try {
				const response = await fetch( url, { ...options, credentials: 'include', signal: controller.signal } );
				const text = await response.text();
				let data = null;
				try {
					data = JSON.parse( text );
				} catch ( parseError ) {
					throw new Error( `Invalid JSON response (${ response.status }): ${ text.slice( 0, 180 ) }` );
				}
				if ( ! response.ok && data && data.message ) {
					throw new Error( data.message );
				}
				return data;
			} finally {
				window.clearTimeout( timer );
			}
		}

		applyBackendErrors( errors ) {
			const keys = Object.keys( errors );
			keys.forEach( ( key ) => {
				const message = Array.isArray( errors[ key ] ) ? errors[ key ][0] : errors[ key ];
				if ( key.startsWith( 'issues.' ) ) {
					const [ , idx, raw ] = key.split( '.' );
					const fieldMap = { url: 'page_url', urgency_level: 'urgency' };
					const field = fieldMap[ raw ] || raw || 'description';
					this.setIssueError( parseInt( idx, 10 ), field, message );
					return;
				}
				this.setFieldError( key, message );
			} );
			const firstKey = keys[0] || '';
			this.switchStep( this.resolveStepByError( firstKey ) );
		}

		resolveStepByError( key ) {
			if ( key === 'service_type' ) {
				return 'service';
			}
			if ( key === 'website_url' || key === 'selected_domain' ) {
				return 'validation';
			}
			if ( [ 'email', 'first_name', 'last_name' ].includes( key ) ) {
				return 'contact';
			}
			if ( key.startsWith( 'issues.' ) || [ 'title', 'message', 'attachments' ].includes( key ) ) {
				return 'details';
			}
			return 'review';
		}

		setFieldError( key, message ) {
			const input = this.form.querySelector( `[data-error-key="${ key }"], [name="${ key }"]` );
			const error = this.form.querySelector( `[data-field-error-for="${ key }"]` );
			if ( error ) {
				error.textContent = message;
			}
			if ( input ) {
				input.classList.add( 'is-invalid' );
				input.setAttribute( 'aria-invalid', 'true' );
				if ( error && error.id ) {
					input.setAttribute( 'aria-describedby', error.id );
				}
			}
		}

		setIssueError( index, field, message ) {
			const card = this.issuesContainer.querySelector( `[data-issue-index="${ index }"]` );
			if ( ! card ) {
				return;
			}
			const input = card.querySelector( `[data-issue-field="${ field }"]` );
			const error = card.querySelector( `[data-issue-error="${ field }"]` );
			if ( error ) {
				error.textContent = message;
			}
			if ( input ) {
				input.classList.add( 'is-invalid' );
				input.setAttribute( 'aria-invalid', 'true' );
				if ( error && error.id ) {
					input.setAttribute( 'aria-describedby', error.id );
				}
			}
		}

		clearErrorByInput( input ) {
			input.classList.remove( 'is-invalid' );
			input.removeAttribute( 'aria-invalid' );
			const key = input.getAttribute( 'data-error-key' );
			if ( key ) {
				const error = this.form.querySelector( `[data-field-error-for="${ key }"]` );
				if ( error ) {
					error.textContent = '';
				}
			}
			const issueField = input.getAttribute( 'data-issue-field' );
			if ( issueField ) {
				const card = input.closest( '[data-issue-card]' );
				const error = card.querySelector( `[data-issue-error="${ issueField }"]` );
				if ( error ) {
					error.textContent = '';
				}
			}
		}

		clearErrors() {
			this.form.querySelectorAll( '.is-invalid' ).forEach( ( el ) => el.classList.remove( 'is-invalid' ) );
			this.form.querySelectorAll( '.kgr-error' ).forEach( ( el ) => {
				el.textContent = '';
			} );
		}

		showAlert( type, message ) {
			this.alert.classList.remove( 'kgr-alert--hidden', 'kgr-alert--error', 'kgr-alert--success' );
			this.alert.classList.add( type === 'success' ? 'kgr-alert--success' : 'kgr-alert--error' );
			this.alert.textContent = message;
		}

		clearAlert() {
			this.alert.classList.add( 'kgr-alert--hidden' );
			this.alert.classList.remove( 'kgr-alert--error', 'kgr-alert--success' );
			this.alert.textContent = '';
		}

		setSubmittingState( submitting ) {
			this.submitting = submitting;
			const submitBtn = this.form.querySelector( '[data-action="submit"]' );
			if ( submitBtn ) {
				submitBtn.disabled = submitting;
			}
			if ( this.submitSpinner ) {
				this.submitSpinner.classList.toggle( 'kgr-hidden', ! submitting );
			}
			if ( this.submitLabel ) {
				this.submitLabel.classList.toggle( 'kgr-hidden', !! submitting );
			}
		}

		showFileReselectHints() {
			this.root.querySelectorAll( '[data-file-reselect-note]' ).forEach( ( node ) => {
				node.classList.remove( 'kgr-hint--hidden' );
				node.textContent = this.config.i18n.filesNeedReselect || 'If your browser cleared file selections, please reselect files before submitting again.';
			} );
		}

		setSubmitState( mode, message ) {
			this.backToReviewBtn.classList.toggle( 'kgr-hidden', mode !== 'error' );
			if ( mode === 'pending' ) {
				this.submitStateIcon.textContent = '...';
				this.submitStateTitle.textContent = 'Submitting your request...';
				this.submitStateMessage.textContent = 'Please wait while we send your request.';
				return;
			}
			if ( mode === 'success' ) {
				this.submitStateIcon.textContent = '\u2713';
				this.submitStateTitle.textContent = 'Request submitted successfully';
				this.submitStateMessage.textContent = message || 'We sent your request. Please check your inbox for confirmation.';
				return;
			}
			this.submitStateIcon.textContent = '!';
			this.submitStateTitle.textContent = 'Submission failed';
			this.submitStateMessage.textContent = message || this.config.i18n.genericError;
		}

		renderFileList( uploadWrap, files, emptyText, input = null ) {
			if ( ! uploadWrap ) {
				return;
			}
			const previewBox = uploadWrap.querySelector( '[data-preview-container]' );
			const output = uploadWrap.querySelector( '[data-file-list]' );

			if ( output ) {
				if ( ! files.length ) {
					output.textContent = emptyText;
				} else {
					const names = files.slice( 0, 3 ).map( ( file ) => file.name );
					const suffix = files.length > 3 ? ` +${ files.length - 3 } more` : '';
					output.textContent = `${ files.length } file(s): ${ names.join( ', ' ) }${ suffix }`;
				}
			}

			if ( ! previewBox ) {
				return;
			}

			previewBox.innerHTML = '';
			if ( ! files.length ) {
				return;
			}

			previewBox.style.display = 'flex';
			previewBox.style.flexWrap = 'wrap';
			previewBox.style.gap = '10px';
			previewBox.style.marginTop = '10px';

			files.forEach( ( file, fileIndex ) => {
				const item = document.createElement( 'div' );
				item.style.position = 'relative';
				item.style.width = '88px';
				item.style.height = '88px';
				item.style.border = '1px solid #e2e8f0';
				item.style.borderRadius = '8px';
				item.style.background = '#f8fafc';
				item.style.overflow = 'hidden';
				item.style.display = 'flex';
				item.style.alignItems = 'center';
				item.style.justifyContent = 'center';

				const removeBtn = document.createElement( 'button' );
				removeBtn.type = 'button';
				removeBtn.setAttribute( 'aria-label', `Remove ${ file.name }` );
				removeBtn.textContent = '×';
				removeBtn.style.position = 'absolute';
				removeBtn.style.top = '6px';
				removeBtn.style.right = '6px';
				removeBtn.style.width = '20px';
				removeBtn.style.height = '20px';
				removeBtn.style.border = 'none';
				removeBtn.style.borderRadius = '50%';
				removeBtn.style.background = '#dc2626';
				removeBtn.style.color = '#ffffff';
				removeBtn.style.cursor = 'pointer';
				removeBtn.style.fontSize = '16px';
				removeBtn.style.fontWeight = '700';
				removeBtn.style.lineHeight = '18px';
				removeBtn.style.padding = '0';
				removeBtn.style.zIndex = '2';

				removeBtn.addEventListener( 'click', () => {
					files.splice( fileIndex, 1 );
					this.syncInputFilesFromArray( input, files );
					this.renderFileList( uploadWrap, files, emptyText, input );
					this.clearAlert();
					this.renderPreview();
					this.renderReview();
				} );

				if ( file.type && file.type.startsWith( 'image/' ) ) {
					const img = document.createElement( 'img' );
					img.src = URL.createObjectURL( file );
					img.alt = file.name;
					img.style.width = '100%';
					img.style.height = '100%';
					img.style.objectFit = 'cover';
					item.appendChild( img );
				} else {
					const ext = ( file.name.split( '.' ).pop() || 'FILE' ).toUpperCase();
					const extLabel = document.createElement( 'span' );
					extLabel.textContent = ext;
					extLabel.style.fontSize = '12px';
					extLabel.style.fontWeight = '700';
					extLabel.style.color = '#334155';
					item.appendChild( extLabel );
				}

				item.appendChild( removeBtn );
				previewBox.appendChild( item );
			} );
		}

		syncInputFilesFromArray( input, files ) {
			if ( ! input ) {
				return;
			}
			try {
				const dt = new DataTransfer();
				files.forEach( ( file ) => dt.items.add( file ) );
				input.files = dt.files;
			} catch ( error ) {
				if ( files.length === 0 ) {
					input.value = '';
				}
			}
		}

		setHelperText( scope ) {
			const target = scope || this.root;
			target.querySelectorAll( '[data-screenshot-rule]' ).forEach( ( node ) => {
				node.textContent = this.config.i18n.screenshotRule || 'Upload up to 2 images, max 1MB each.';
			} );
			target.querySelectorAll( '[data-non-website-file-rule]' ).forEach( ( node ) => {
				node.textContent = this.config.i18n.nonWebsiteUploadRule || 'Accepted files up to total 10MB.';
			} );
		}

		applyQaHintsVisibility() {
			const enabled = !! this.config.qaHintsEnabled;
			this.root.querySelectorAll( '[data-kgr-qa-hint]' ).forEach( ( node ) => {
				node.classList.toggle( 'kgr-hidden', ! enabled );
			} );
		}

		initQaHintComponents() {
			this.root.querySelectorAll( '[data-kgr-copy-text]' ).forEach( ( node ) => {
				if ( node.dataset.kgrCopyBound === '1' ) {
					return;
				}
				node.dataset.kgrCopyBound = '1';
				node.addEventListener( 'click', async ( event ) => {
					event.preventDefault();
					const text = ( node.getAttribute( 'data-kgr-copy-text' ) || '' ).trim();
					if ( ! text ) {
						return;
					}
					const copied = await this.copyTextToClipboard( text );
					if ( copied ) {
						this.showCopyToast( node );
					}
				} );
			} );
		}

		async copyTextToClipboard( text ) {
			try {
				if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
					await navigator.clipboard.writeText( text );
					return true;
				}
			} catch ( error ) {
				// fall through to legacy method
			}

			try {
				const ta = document.createElement( 'textarea' );
				ta.value = text;
				ta.setAttribute( 'readonly', 'readonly' );
				ta.style.position = 'fixed';
				ta.style.left = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				ta.setSelectionRange( 0, ta.value.length );
				const ok = document.execCommand( 'copy' );
				document.body.removeChild( ta );
				return !! ok;
			} catch ( error ) {
				return false;
			}
		}

		showCopyToast( node ) {
			const toast = node.querySelector( '.kgr-qa-copy__toast' );
			if ( ! toast ) {
				return;
			}
			const label = node.getAttribute( 'data-kgr-copy-label' );
			if ( label ) {
				toast.textContent = label;
			}
			toast.classList.add( 'is-visible' );
			toast.setAttribute( 'aria-hidden', 'false' );
			if ( node._kgrCopyToastTimer ) {
				window.clearTimeout( node._kgrCopyToastTimer );
			}
			node._kgrCopyToastTimer = window.setTimeout( () => {
				toast.classList.remove( 'is-visible' );
				toast.setAttribute( 'aria-hidden', 'true' );
			}, 2000 );
		}

		renderPreview() {
			// Preview is now handled reactively by Alpine.js in the template
		}

		previewSection( title, rows, sectionIndex ) {
			const rowsHtml = rows.map( ( row ) => `<div class="kgr-preview__line"><span>${ this.escape( row[0] ) }</span><span>${ this.escape( row[1] ) }</span></div>` ).join( '' );
			return `<section class="kgr-preview__section"><h4>${ this.escape( title ) }</h4>${ rowsHtml }</section>`;
		}

		renderReview() {
			const blocks = [];
			blocks.push( this.reviewSection( 'Service information', [
				[ 'Service', this.state.service_type || '-' ],
				[ 'Website', this.state.website_url || '-' ],
			] ) );
			blocks.push( this.reviewSection( 'Contact details', [
				[ 'First name', this.state.first_name || '-' ],
				[ 'Last name', this.state.last_name || '-' ],
				[ 'Email', this.state.email || '-' ],
			] ) );
			if ( this.state.service_type === 'Website' ) {
				const issues = this.state.issues.map( ( issue, i ) => `
					<div class="kgr-review__issue">
						<strong>Issue ${ i + 1 }</strong><br>
						Page: ${ this.escape( issue.page_url || '-' ) }<br>
						Type: ${ this.escape( issue.issue_type || '-' ) }<br>
						Urgency: ${ this.escape( issue.urgency || '-' ) }<br>
						Description: ${ this.escape( issue.description || '-' ) }<br>
						Screenshots: ${( issue.screenshots || [] ).length}
					</div>
				` ).join( '' );
				blocks.push( `<section class="kgr-review__section"><h4>Issues summary</h4>${ issues || '<p>No issues added.</p>' }</section>` );
			} else {
				blocks.push( this.reviewSection( 'Request details', [
					[ 'Title', this.state.request_title || '-' ],
					[ 'Message', this.state.message || '-' ],
					[ 'Attachments', this.state.attachments.length ? `${ this.state.attachments.length } file(s)` : 'No files' ],
				] ) );
			}
			this.reviewContainer.innerHTML = blocks.join( '' );
		}

		reviewSection( title, lines ) {
			const rows = lines.map( ( item ) => `<div class="kgr-review__line"><span>${ this.escape( item[0] ) }</span><span>${ this.escape( item[1] ) }</span></div>` ).join( '' );
			return `<section class="kgr-review__section"><h4>${ this.escape( title ) }</h4>${ rows }</section>`;
		}

		isValidUrl( value ) {
			const normalized = this.normalizeUrlInput( value );
			if ( ! normalized ) {
				return false;
			}
			try {
				const parsed = new URL( normalized );
				// Basic check for a dot in the hostname to require a TLD-like structure
				return parsed.hostname && parsed.hostname.includes( '.' );
			} catch ( error ) {
				return false;
			}
		}

		isValidWebsiteInput( value ) {
			const input = ( value || '' ).trim().toLowerCase();
			if ( ! input ) {
				return false;
			}
			
			const withScheme = input.includes( '://' ) ? input : `https://${ input }`;
			try {
				const parsed = new URL( withScheme );
				const host = ( parsed.hostname || '' ).replace( /^www\./, '' );
				// Enforce pattern: something.tld (at least one dot and 2+ char TLD)
				return /^[a-z0-9.-]+\.[a-z]{2,}$/i.test( host );
			} catch ( error ) {
				return false;
			}
		}

		normalizeUrlInput( value ) {
			const input = ( value || '' ).trim();
			if ( ! input ) {
				return '';
			}
			const withScheme = input.includes( '://' ) ? input : `https://${ input }`;
			try {
				const parsed = new URL( withScheme );
				return parsed.toString();
			} catch ( error ) {
				return '';
			}
		}

		escape( value ) {
			return ( value || '' ).toString()
				.replaceAll( '&', '&amp;' )
				.replaceAll( '<', '&lt;' )
				.replaceAll( '>', '&gt;' )
				.replaceAll( '"', '&quot;' )
				.replaceAll( '\'', '&#39;' );
		}

		initCharCounters() {
			this.form.querySelectorAll( 'textarea' ).forEach( ( ta ) => this.setupCharCounter( ta ) );
		}

		setupCharCounter( textarea ) {
			if ( textarea.dataset.hasCounter ) return;
			textarea.dataset.hasCounter = 'true';

			const counter = document.createElement( 'div' );
			counter.className = 'kgr-char-count';
			counter.style.fontSize = '0.75rem';
			counter.style.textAlign = 'right';
			counter.style.marginTop = '-0.2rem';
			counter.style.marginBottom = '0.2rem';
			
			textarea.parentNode.insertBefore( counter, textarea.nextSibling );
			
			this.updateCharCount( textarea, counter );
			
			textarea.addEventListener( 'input', () => this.updateCharCount( textarea, counter ) );
		}

		updateCharCount( textarea, counter ) {
			const len = textarea.value.length;
			counter.textContent = `${ len } / 20 min`;
			counter.style.color = len < 20 ? 'red' : '#00001a';
		}

		renderFilePreviews( input, filesArray, isNonWebsite, index ) {
			let container = input.parentNode.querySelector( '.kgr-file-previews' );
			if ( ! container ) {
				container = document.createElement( 'div' );
				container.className = 'kgr-file-previews';
				container.style.display = 'flex';
				container.style.flexWrap = 'wrap';
				container.style.gap = '12px';
				container.style.marginTop = '12px';
				input.parentNode.appendChild( container );
			}
			container.innerHTML = '';

			filesArray.forEach( ( file, fileIndex ) => {
				const wrapper = document.createElement( 'div' );
				wrapper.style.position = 'relative';
				wrapper.style.width = '80px';
				wrapper.style.height = '80px';
				wrapper.style.border = '1px solid #e2e8f0';
				wrapper.style.borderRadius = '6px';
				wrapper.style.display = 'flex';
				wrapper.style.alignItems = 'center';
				wrapper.style.justifyContent = 'center';
				wrapper.style.backgroundColor = '#f8fafc';
				wrapper.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

				const closeBtn = document.createElement( 'button' );
				closeBtn.innerHTML = '&times;';
				closeBtn.type = 'button';
				closeBtn.style.position = 'absolute';
				closeBtn.style.top = '-6px';
				closeBtn.style.right = '-6px';
				closeBtn.style.width = '20px';
				closeBtn.style.height = '20px';
				closeBtn.style.borderRadius = '50%';
				closeBtn.style.backgroundColor = '#ef4444';
				closeBtn.style.color = '#ffffff';
				closeBtn.style.border = 'none';
				closeBtn.style.cursor = 'pointer';
				closeBtn.style.display = 'flex';
				closeBtn.style.alignItems = 'center';
				closeBtn.style.justifyContent = 'center';
				closeBtn.style.fontSize = '14px';
				closeBtn.style.lineHeight = '1';
				closeBtn.style.zIndex = '10';
				closeBtn.style.padding = '0';
				closeBtn.style.paddingBottom = '1px';

				closeBtn.addEventListener( 'click', () => {
					filesArray.splice( fileIndex, 1 );
					try {
						const dt = new DataTransfer();
						filesArray.forEach( f => dt.items.add( f ) );
						input.files = dt.files;
					} catch ( e ) {
						if ( filesArray.length === 0 ) input.value = '';
					}
					this.renderFilePreviews( input, filesArray, isNonWebsite, index );
				} );

				if ( file.type.startsWith( 'image/' ) ) {
					const img = document.createElement( 'img' );
					img.src = URL.createObjectURL( file );
					img.style.width = '100%';
					img.style.height = '100%';
					img.style.objectFit = 'cover';
					img.style.borderRadius = '5px';
					wrapper.appendChild( img );
				} else {
					const ext = file.name.split( '.' ).pop().toUpperCase();
					const icon = document.createElement( 'div' );
					icon.style.fontWeight = 'bold';
					icon.style.fontSize = '14px';
					icon.style.color = '#475569';
					icon.style.textAlign = 'center';
					icon.style.wordBreak = 'break-word';
					icon.style.padding = '4px';
					icon.textContent = ext;
					wrapper.appendChild( icon );
				}

				wrapper.appendChild( closeBtn );
				container.appendChild( wrapper );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		document.querySelectorAll( '[data-kgr-root]' ).forEach( ( root ) => {
			new SupportFormUI( root, window.KGR_CONFIG || {} ).init();
		} );
	} );
}() );
