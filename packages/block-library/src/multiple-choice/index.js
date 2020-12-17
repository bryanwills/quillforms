/**
 * Internal Dependencies.
 */
import output from './output';
import controls from './controls';
import metadata from './block.json';
import logicControl from '../dropdown/logic-control';

/**
 * External Dependencies
 */
import CheckIcon from '@material-ui/icons/Check';

const { type } = metadata;

export { type, metadata };

export const settings = {
	editorConfig: {
		color: '#9e5210',
		icon: CheckIcon,
		controls,
		logicControl,
	},
	rendererConfig: {
		output,
		getMergeTagVal: ( val ) =>
			val.map( ( choice ) => choice.label ).join( ', ' ),
	},
};
