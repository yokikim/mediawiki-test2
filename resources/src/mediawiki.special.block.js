/*!
 * JavaScript for Special:Block
 */
( function () {
	// Like OO.ui.infuse(), but if the element doesn't exist, return null instead of throwing an exception.
	function infuseIfExists( $el ) {
		if ( !$el.length ) {
			return null;
		}
		return OO.ui.infuse( $el );
	}

	$( function () {
		var blockTargetWidget, anonOnlyWidget, enableAutoblockWidget, hideUserWidget, watchUserWidget,
			expiryWidget, editingWidget, editingRestrictionWidget, preventTalkPageEditWidget,
			pageRestrictionsWidget, namespaceRestrictionsWidget, createAccountWidget, data,
			enablePartialBlocks, blockAllowsUTEdit, userChangedCreateAccount, updatingBlockOptions;

		function updateBlockOptions() {
			var blocktarget = blockTargetWidget.getValue().trim(),
				isEmpty = blocktarget === '',
				isIp = mw.util.isIPAddress( blocktarget, true ),
				isIpRange = isIp && blocktarget.match( /\/\d+$/ ),
				isNonEmptyIp = isIp && !isEmpty,
				expiryValue = expiryWidget.getValue(),
				// infinityValues are the values the SpecialBlock class accepts as infinity (sf. wfIsInfinity)
				infinityValues = [ 'infinite', 'indefinite', 'infinity', 'never' ],
				isIndefinite = infinityValues.indexOf( expiryValue ) !== -1,
				editingRestrictionValue = enablePartialBlocks ? editingRestrictionWidget.getValue() : 'sitewide',
				editingIsSelected = editingWidget.isSelected(),
				isSitewide = editingIsSelected && editingRestrictionValue === 'sitewide';

			enableAutoblockWidget.setDisabled( isNonEmptyIp );
			if ( enableAutoblockWidget.isDisabled() ) {
				enableAutoblockWidget.setSelected( false );
			}

			anonOnlyWidget.setDisabled( !isIp && !isEmpty );
			if ( anonOnlyWidget.isDisabled() ) {
				anonOnlyWidget.setSelected( false );
			}

			if ( hideUserWidget ) {
				hideUserWidget.setDisabled( isNonEmptyIp || !isIndefinite || !isSitewide );
				if ( hideUserWidget.isDisabled() ) {
					hideUserWidget.setSelected( false );
				}
			}

			if ( watchUserWidget ) {
				watchUserWidget.setDisabled( isIpRange && !isEmpty );
				if ( watchUserWidget.isDisabled() ) {
					watchUserWidget.setSelected( false );
				}
			}

			if ( enablePartialBlocks ) {
				editingRestrictionWidget.setDisabled( !editingIsSelected );
				pageRestrictionsWidget.setDisabled( !editingIsSelected || isSitewide );
				namespaceRestrictionsWidget.setDisabled( !editingIsSelected || isSitewide );
				if ( blockAllowsUTEdit ) {
					// This option is disabled for partial blocks unless a namespace restriction
					// for the User_talk namespace is in place.
					preventTalkPageEditWidget.setDisabled(
						editingIsSelected &&
						editingRestrictionValue === 'partial' &&
						namespaceRestrictionsWidget.getValue().indexOf(
							String( mw.config.get( 'wgNamespaceIds' ).user_talk )
						) === -1
					);
				}
			}

			if ( !userChangedCreateAccount ) {
				updatingBlockOptions = true;
				createAccountWidget.setSelected( isSitewide );
				updatingBlockOptions = false;
			}

		}

		// This code is also loaded on the "block succeeded" page where there is no form,
		// so check for block target widget; if it exists, the form is present
		blockTargetWidget = infuseIfExists( $( '#mw-bi-target' ) );

		if ( blockTargetWidget ) {
			data = require( './config.json' );
			enablePartialBlocks = data.EnablePartialBlocks;
			blockAllowsUTEdit = data.BlockAllowsUTEdit;
			userChangedCreateAccount = mw.config.get( 'wgCreateAccountDirty' );
			updatingBlockOptions = false;

			// Always present if blockTargetWidget is present
			editingWidget = OO.ui.infuse( $( '#mw-input-wpEditing' ) );
			expiryWidget = OO.ui.infuse( $( '#mw-input-wpExpiry' ) );
			createAccountWidget = OO.ui.infuse( $( '#mw-input-wpCreateAccount' ) );
			enableAutoblockWidget = OO.ui.infuse( $( '#mw-input-wpAutoBlock' ) );
			anonOnlyWidget = OO.ui.infuse( $( '#mw-input-wpHardBlock' ) );
			blockTargetWidget.on( 'change', updateBlockOptions );
			editingWidget.on( 'change', updateBlockOptions );
			expiryWidget.on( 'change', updateBlockOptions );
			createAccountWidget.on( 'change', function () {
				if ( !updatingBlockOptions ) {
					userChangedCreateAccount = true;
				}
			} );

			// Present for certain rights
			watchUserWidget = infuseIfExists( $( '#mw-input-wpWatch' ) );
			hideUserWidget = infuseIfExists( $( '#mw-input-wpHideUser' ) );

			// Present for certain global configs
			if ( enablePartialBlocks ) {
				editingRestrictionWidget = OO.ui.infuse( $( '#mw-input-wpEditingRestriction' ) );
				pageRestrictionsWidget = OO.ui.infuse( $( '#mw-input-wpPageRestrictions' ) );
				namespaceRestrictionsWidget = OO.ui.infuse( $( '#mw-input-wpNamespaceRestrictions' ) );
				editingRestrictionWidget.on( 'change', updateBlockOptions );
				namespaceRestrictionsWidget.on( 'change', updateBlockOptions );
			}
			if ( blockAllowsUTEdit ) {
				preventTalkPageEditWidget = infuseIfExists( $( '#mw-input-wpDisableUTEdit' ) );
			}

			updateBlockOptions();
		}
	} );
}() );
