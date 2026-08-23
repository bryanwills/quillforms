/**
 * WordPress Dependencies
 */
import { TabPanel } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';

/**
 * Quill Forms Dependencies
 */
import ConfigAPI from '@quillforms/config';

/**
 * External Dependencies
 */
import { css } from 'emotion';

/**
 * Internal Dependencies
 */
import './style.scss';
import General from './general';
import Payments from './payments';
import Analytics from './analytics';
import Integrations from './integrations';
import Emails from './emails';
import ReCAPTCHA from './recaptcha';
import Geolocation from './geolocation';
import MCP from './mcp';


const Settings = () => {
	const isWPEnv = ConfigAPI.isWPEnv();
	const params = new Proxy(new URLSearchParams(window.location.search), {
		get: (searchParams, prop) => searchParams.get(prop),
	});

	let Tabs = {
		general: {
			title: 'General',
			render: <General />,
		},
		payments: {
			title: 'Payments',
			render: <Payments />,
		},
		emails: {
			title: 'Emails',
			render: <Emails />,
		},
		integrations: {
			title: 'Integrations',
			render: <Integrations />,
		},
		recaptcha: {
			title: 'reCAPTCHA',
			render: <ReCAPTCHA />,
		},
		analytics: {
			title: 'Tracking & Analytics',
			render: <Analytics />,
		},
		geolocation: {
			title: 'Geolocation',
			render: <Geolocation />,
		},
		mcp: {
			title: 'MCP Server',
			render: <MCP />,
		},
	};

	/**
	 * Filters the settings page tabs.
	 *
	 * Lets an addon add its own settings tab without core having to know about
	 * it. Each entry is keyed by tab slug and takes { title, render }; the key
	 * is also what ?tab= matches, so an addon can deep link to its own tab.
	 *
	 * @param {Object} Tabs Tabs keyed by slug.
	 */
	Tabs = applyFilters('QuillForms.Settings.Tabs', Tabs);
	// if (!isWPEnv) {
	// 	// keep all tabs but we need general tab to be the last one in case of non WP env 
	// 	// remove general tab first
	// 	delete Tabs.general;
	// 	// add it again
	// 	Tabs = {
	// 		...Tabs,
	// 		general: {
	// 			title: 'General',
	// 			render: <General />,
	// 		},
	// 	};
	// }
	return (
		<div className="quillforms-settings-page">
			<h1 className="quillforms-settings-page__heading">Settings</h1>
			<div className="quillforms-settings-page__body">
				<TabPanel
					className={css`
						.components-tab-panel__tabs-item {
							font-weight: normal;
						}
						.active-tab {
							font-weight: bold;
						}
					` }
					activeClass="active-tab"
					tabs={Object.entries(Tabs).map(([name, tab]) => {
						return {
							name,
							title: tab.title,
							className: 'tab-' + name,
						};
					})}
					initialTabName={!isWPEnv ? 'payments' : params?.tab ? params.tab : 'general'}
				>
					{(tab) => (
						<div>
							{Tabs[tab.name]?.render ?? <div>Not Found</div>}
						</div>
					)}
				</TabPanel>
			</div>
		</div>
	);
};

export default Settings;
