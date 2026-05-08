/* global KGR_CONFIG */
( function () {
	'use strict';

	async function getGoogleCaptchaToken( config ) {
		const siteKey = ( config.googleSiteKey || '' ).toString().trim();
		const action = ( config.googleRecaptchaAction || 'support_submit' ).toString();
		if ( ! siteKey ) {
			throw new Error( 'Google reCAPTCHA is not configured.' );
		}
		if ( ! window.grecaptcha || typeof window.grecaptcha.execute !== 'function' ) {
			throw new Error( 'Google reCAPTCHA is unavailable. Please refresh and try again.' );
		}
		return new Promise( ( resolve, reject ) => {
			window.grecaptcha.ready( async () => {
				try {
					const token = await window.grecaptcha.execute( siteKey, { action } );
					resolve( token || '' );
				} catch ( error ) {
					reject( new Error( 'Google reCAPTCHA validation failed. Please try again.' ) );
				}
			} );
		} );
	}

	async function getCloudflareCaptchaToken( form, config, context ) {
		const siteKey = ( config.cloudflareSiteKey || '' ).toString().trim();
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
						callback: ( token ) => resolve( token || '' ),
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
				formData.append( 'captcha_token', captchaToken );
				formData.append( 'cf-turnstile-response', captchaToken );
				formData.append( 'g-recaptcha-response', captchaToken );
			}
		},
	};
}() );
