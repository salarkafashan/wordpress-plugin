/* global KGR_CONFIG */
( function () {
	'use strict';

	function maskValue( value ) {
		const val = ( value || '' ).toString().trim();
		if ( ! val ) {
			return { exists: false, length: 0, first4: '', last6: '' };
		}
		return {
			exists: true,
			length: val.length,
			first4: val.substring( 0, 4 ),
			last6: val.length >= 6 ? val.substring( val.length - 6 ) : val
		};
	}

	function debugLog( config, message, data ) {
		if ( config && config.captchaDebug ) {
			console.log( '[Captcha Debug] ' + message, data );
		}
	}

	async function getGoogleCaptchaToken( config ) {
		const siteKey = ( config.googleSiteKey || '' ).toString().trim();
		const action = ( config.googleRecaptchaAction || 'submit' ).toString();
		const type = ( config.googleRecaptchaType || 'classic' ).toString().toLowerCase();

		debugLog( config, 'Starting Google reCAPTCHA token generation', {
			google_type: type,
			script_loaded: typeof window.grecaptcha !== 'undefined',
			grecaptcha_exists: !! window.grecaptcha,
			grecaptcha_enterprise_exists: !! ( window.grecaptcha && window.grecaptcha.enterprise ),
			site_key: maskValue( siteKey ),
			action: action
		} );

		if ( ! siteKey ) {
			throw new Error( 'Google reCAPTCHA is not configured.' );
		}
		if ( ! window.grecaptcha ) {
			throw new Error( 'Google reCAPTCHA is unavailable. Please refresh and try again.' );
		}

		const recaptcha = type === 'enterprise' ? window.grecaptcha.enterprise : window.grecaptcha;
		if ( ! recaptcha || typeof recaptcha.execute !== 'function' || typeof recaptcha.ready !== 'function' ) {
			throw new Error( 'Google reCAPTCHA is unavailable. Please refresh and try again.' );
		}

		return new Promise( ( resolve, reject ) => {
			recaptcha.ready( async () => {
				try {
					const token = await recaptcha.execute( siteKey, { action } );
					debugLog( config, 'Google reCAPTCHA token generated', {
						token_generated: !! token,
						token_info: maskValue( token )
					} );
					resolve( token || '' );
				} catch ( error ) {
					debugLog( config, 'Google reCAPTCHA token generation failed', { error: error.message } );
					reject( new Error( 'Google reCAPTCHA validation failed. Please try again.' ) );
				}
			} );
		} );
	}

	async function getCloudflareCaptchaToken( form, config, context ) {
		const siteKey = ( config.cloudflareSiteKey || '' ).toString().trim();

		debugLog( config, 'Starting Cloudflare Turnstile token generation', {
			site_key: maskValue( siteKey ),
			turnstile_exists: !! window.turnstile
		} );

		if ( ! siteKey ) {
			throw new Error( 'Cloudflare Turnstile is not configured.' );
		}
		if ( ! window.turnstile || typeof window.turnstile.render !== 'function' || typeof window.turnstile.execute !== 'function' ) {
			throw new Error( 'Cloudflare Turnstile is unavailable. Please refresh and try again.' );
		}

		let container = form.querySelector( '[data-kgr-turnstile]' );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.setAttribute( 'data-kgr-turnstile', '1' );
			container.className = 'kgr-hidden';
			form.appendChild( container );
		}

		return new Promise( ( resolve, reject ) => {
			try {
				if ( context.turnstileWidgetId === null ) {
					context.turnstileWidgetId = window.turnstile.render( container, {
						sitekey: siteKey,
						size: 'invisible',
						callback: ( token ) => {
							debugLog( config, 'Cloudflare Turnstile token generated', {
								token_generated: !! token,
								token_info: maskValue( token )
							} );
							resolve( token || '' );
						},
						'error-callback': () => reject( new Error( 'Cloudflare Turnstile validation failed. Please try again.' ) ),
						'expired-callback': () => reject( new Error( 'Cloudflare Turnstile expired. Please try again.' ) ),
					} );
				}
				window.turnstile.execute( context.turnstileWidgetId );
			} catch ( error ) {
				reject( new Error( 'Cloudflare Turnstile validation failed. Please try again.' ) );
			}
		} );
	}

	async function getCaptchaToken( form, config, context ) {
		const provider = ( config.captchaProvider || 'none' ).toString().toLowerCase();
		if ( provider === '' || provider === 'none' ) {
			return '';
		}
		if ( provider === 'google' ) {
			return getGoogleCaptchaToken( config );
		}
		if ( provider === 'cloudflare' ) {
			return getCloudflareCaptchaToken( form, config, context );
		}
		throw new Error( 'Unsupported captcha provider configuration.' );
	}

	window.KGRFormSecurity = {
		async appendSecurityPayload( form, config, rawState, context, formData ) {
			const startedAt = rawState.form_started_at || Math.floor( Date.now() / 1000 );
			formData.append( 'form_started_at', String( startedAt ) );

			const honeypotField = ( config.honeypotFieldName || 'company_website' ).toString();
			formData.append( honeypotField, '' );

			const captchaToken = await getCaptchaToken( form, config, context );
			if ( captchaToken ) {
				formData.set( 'captcha_token', captchaToken );
				formData.set( 'cf-turnstile-response', captchaToken );
				formData.set( 'g-recaptcha-response', captchaToken );
				
				debugLog( config, 'Captcha payload appended to FormData', {
					captcha_token_length: captchaToken.length,
					g_recaptcha_response_length: captchaToken.length,
					payload_keys: Array.from( formData.keys() )
				} );
			} else {
				debugLog( config, 'No captcha token generated to append' );
			}
		},
	};
}() );
