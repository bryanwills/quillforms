/**
 * QuillForms Depndencies
 */
import { useTheme, useMessages } from '@quillforms/renderer-core';

/**
 * WordPress Dependencies
 */
import { useState, useEffect } from '@wordpress/element';

/**
 * External Dependencies
 */
import tinyColor from 'tinycolor2';
import { css } from 'emotion';
import classnames from 'classnames';

const NumberOutput = ( props ) => {
	const {
		id,
		attributes,
		setIsValid,
		setIsAnswered,
		setValidationErr,
		showNextBtn,
		blockWithError,
		val,
		setVal,
		showErrMsg,
		inputRef,
		isTouchScreen,
		setFooterDisplay,
	} = props;
	const { setMax, max, setMin, min, required } = attributes;
	const messages = useMessages();
	const theme = useTheme();
	const answersColor = tinyColor( theme.answersColor );

	const checkfieldValidation = ( value ) => {
		if ( required === true && ( ! value || value === '' ) ) {
			setIsValid( false );
			setValidationErr( messages[ 'label.errorAlert.required' ] );
		} else if ( setMax && max > 0 && value > max ) {
			setIsValid( false );
			setValidationErr( messages[ 'label.errorAlert.maxNum' ] );
		} else if ( setMin && min >= 0 && value < min ) {
			setIsValid( false );
			setValidationErr( messages[ 'label.errorAlert.minNum' ] );
		} else {
			setIsValid( true );
			setValidationErr( null );
		}
	};

	useEffect( () => {
		checkfieldValidation( val );
	}, [ attributes ] );

	const changeHandler = ( e ) => {
		const value = e.target.value;
		if ( isNaN( value ) ) {
			blockWithError( 'Numbers only!' );
			return;
		}
		setVal( parseInt( value ) );
		showErrMsg( false );
		checkfieldValidation( parseInt( value ) );

		if ( value ) {
			setIsAnswered( true );
			showNextBtn( true );
		} else {
			setIsAnswered( false );
			showNextBtn( false );
		}
	};

	return (
		<input
			ref={ inputRef }
			className={ classnames(
				css`
					& {
						margin-top: 15px;
						width: 100%;
						border: none;
						outline: none;
						font-size: 30px;
						padding-bottom: 8px;
						background: transparent;
						transition: box-shadow 0.1s ease-out 0s;
						box-shadow: ${ answersColor.setAlpha( 0.3 ).toString() }
							0px 1px;
						@media ( max-width: 600px ) {
							font-size: 24px;
						}

						@media ( max-width: 400px ) {
							font-size: 20px;
						}
					}

					&::placeholder {
						opacity: 0.3;
						/* Chrome, Firefox, Opera, Safari 10.1+ */
						color: ${ theme.answersColor };
					}

					&:-ms-input-placeholder {
						opacity: 0.3;
						/* Internet Explorer 10-11 */
						color: ${ theme.answersColor };
					}

					&::-ms-input-placeholder {
						opacity: 0.3;
						/* Microsoft Edge */
						color: ${ theme.answersColor };
					}

					&:focus {
						box-shadow: ${ answersColor.setAlpha( 1 ).toString() }
							0px 2px;
					}

					color: ${ theme.answersColor };
				`
			) }
			id={ 'number-' + id }
			placeholder={ messages[ 'block.number.placeholder' ] }
			onChange={ changeHandler }
			value={ val ? val : '' }
			onFocus={ () => {
				if ( isTouchScreen ) {
					setFooterDisplay( false );
				}
			} }
			onBlur={ () => {
				if ( isTouchScreen ) {
					setFooterDisplay( true );
				}
			} }
		/>
	);
};
export default NumberOutput;
