/**
 * Internal Dependencies
 */
import controls from './controls';
import logicControl from './logic-control';
import Icon from './icon';
import { __ } from '@wordpress/i18n';

const blockAdminSettings = {
	color: '#127fa9',
	icon: Icon,
	controls,
	logicControl,
	title: __('Number', 'quillforms'),
	order: 4,
};

export default blockAdminSettings;
