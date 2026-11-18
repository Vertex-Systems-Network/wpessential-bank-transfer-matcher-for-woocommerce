( () => {
	const config = window.wpessentialWbmAdmin || {};
	document.querySelectorAll( '.wbm-api-form' ).forEach( ( form ) => {
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const button = form.querySelector( 'button[type="submit"]' );
			const spinner = form.querySelector( '.spinner' );
			const message = form.querySelector( '.wbm-form-message' );
			const payload = {};
			new FormData( form ).forEach( ( value, key ) => { payload[ key ] = value; } );
			form.querySelectorAll( 'input[type="checkbox"]' ).forEach( ( input ) => { payload[ input.name ] = input.checked; } );
			button.disabled = true;
			spinner.classList.add( 'is-active' );
			message.className = 'wbm-form-message';
			message.textContent = config.processing || 'Processing…';
			try {
				const response = await fetch( config.root + form.dataset.endpoint.replace( /^\//, '' ), {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
					body: JSON.stringify( payload ),
				} );
				const data = await response.json().catch( () => ( {} ) );
				if ( ! response.ok ) throw new Error( data.message || config.failed || 'Action failed.' );
				message.classList.add( 'is-success' );
				message.textContent = config.saved || 'Saved.';
				const url = new URL( window.location.href );
				url.searchParams.set( 'wbm_notice', 'saved' );
				window.setTimeout( () => window.location.assign( url.toString() ), 500 );
			} catch ( error ) {
				message.classList.add( 'is-error' );
				message.textContent = error.message || config.failed || 'Action failed.';
				button.disabled = false;
				spinner.classList.remove( 'is-active' );
			}
		} );
	} );
} )();
