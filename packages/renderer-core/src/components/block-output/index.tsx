/**
 * WordPress Dependencies
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal Dependencies
 */
import { useFieldRenderContext } from '../field-render';
import useBlockTypes from '../../hooks/use-block-types';
import BlockFooter from '../block-footer';

let timer1, timer2;
const BlockOutput = ( { next, isFocused, setIsShaking } ) => {
	const {
		id,
		blockName,
		isActive,
		attributes,
		required,
		blockFooterArea,
		setBlockFooterArea,
	} = useFieldRenderContext();
	const blockTypes = useBlockTypes();
	const blockType = blockTypes[ blockName ];
	const [ shakingErr, setShakingErr ] = useState( null );

	const { isCurrentBlockEditable } = useSelect( ( select ) => {
		return {
			isCurrentBlockEditable: select(
				'quillForms/blocks'
			).hasBlockSupport( blockName, 'editable' ),
		};
	} );
	const { answerValue, isValid } = useSelect( ( select ) => {
		return {
			answerValue: isCurrentBlockEditable
				? select( 'quillForms/renderer-submission' ).getFieldAnswerVal(
						id
				  )
				: null,
			isValid: isCurrentBlockEditable
				? select( 'quillForms/renderer-submission' ).isValidField( id )
				: null,
		};
	} );

	useEffect( () => {
		clearTimeout( timer1 );
		clearTimeout( timer2 );
		setIsShaking( false );
		setShakingErr( null );
	}, [ answerValue ] );

	const shakeWithError = ( err ) => {
		clearTimeout( timer1 );
		clearTimeout( timer2 );
		setIsShaking( true );
		setShakingErr( err );
		timer1 = setTimeout( () => {
			setIsShaking( false );
		}, 600 );
		timer2 = setTimeout( () => {
			setShakingErr( null );
		}, 1800 );
	};

	const showSubmitBtn = ( val ) => {
		setBlockFooterArea( val ? 'submit-btn' : null );
	};

	const showErrorMessage = ( val ) => {
		setBlockFooterArea( val ? 'error-message' : null );
	};

	const isSubmitBtnVisible = blockFooterArea === 'submit-btn';
	const isErrMsgVisible = blockFooterArea === 'error-message';

	const {
		setIsFieldValid,
		setFieldValidationErrr,
		setIsFieldAnswered,
		setFieldAnswer,
	} = useDispatch( 'quillForms/renderer-submission' );

	const goNext = () => {
		if ( ! isValid ) {
			showErrorMessage( true );
		} else {
			next();
		}
	};

	const props = {
		id,
		next: goNext,
		attributes,
		required,
		isFocused,
		isActive,
		isValid,
		val: answerValue,
		setIsValid: ( val ) => setIsFieldValid( id, val ),
		setIsAnswered: ( val ) => setIsFieldAnswered( id, val ),
		setValidationErr: ( val ) => setFieldValidationErrr( id, val ),
		setVal: ( val ) => setFieldAnswer( id, val ),
		showErrorMessage: ( val ) => showErrorMessage( val ),
		showSubmitBtn: ( val ) => showSubmitBtn( val ),
		shakeWithError: ( err ) => shakeWithError( err ),
	};

	return (
		<div
			role="presentation"
			className="renderer-components-block-output"
			onKeyDown={ ( e ) => {
				if ( e.key === 'Enter' ) {
					e.stopPropagation();
					goNext();
				}
			} }
		>
			{ blockType?.output && <blockType.output { ...props } /> }
			<BlockFooter
				next={ next }
				isSubmitBtnVisible={ isSubmitBtnVisible }
				isErrMsgVisible={ isErrMsgVisible }
				showErrorMessage={ showErrorMessage }
				shakingErr={ shakingErr }
			/>
		</div>
	);
};
export default BlockOutput;