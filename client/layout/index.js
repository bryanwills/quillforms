/**
 * QuillForms Dependencies
 */
import {
	getAdminPages,
	Router,
	Route,
	Switch,
	getHistory,
} from '@quillforms/navigation';
import configApi from '@quillforms/config';

/**
 * WordPress Dependencies
 */
import { SlotFillProvider, Modal } from '@wordpress/components';
import { useEffect, useState, useMemo } from '@wordpress/element';
import { PluginArea } from '@wordpress/plugins';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import { forEach, uniq } from 'lodash';
import { ThreeDots as Loader } from 'react-loader-spinner';
import { css } from 'emotion';
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { Controller } from './controller';
import Sidebar from '../components/sidebar';
import Header from '../components/header';

export const Layout = (props) => {
	const { params } = props.match;

	const pluginsArea = useMemo(() => {
		return <PluginArea />;
	}, []);

	const { notices, formBlocks, hiddenFields, variables } = useSelect((select) => {
		return {
			notices: select('core/notices').getNotices(),
			formBlocks: select('quillForms/block-editor').getBlocksWithPartialSubmission(),
			hiddenFields: select('quillForms/hidden-fields-editor')?.getHiddenFields(),
			variables: select('quillForms/logic-editor')?.getLogicVariables(),
		};
	});

	const { invalidateResolutionForStore } = useDispatch('core/data');
	const { removeNotice } = useDispatch('core/notices');

	const [isLoading, setIsLoading] = useState(
		props.page.requiresInitialPayload && params.id
	);

	const invalidateResolutionConnectedStores = () => {
		// Invalidate resolution for all connected stores.
		forEach(uniq(props.page.connectedStores), (store) => {
			if (
				store &&
				wp.data.RegistryConsumer._currentValue.stores[store]
			) {
				invalidateResolutionForStore(store);
			}
		});
	};

	useEffect(() => {
		if (props.page.requiresInitialPayload && params.id) {
			apiFetch({
				path: `/wp/v2/quill_forms/${params.id}`,
				method: 'GET',
			}).then((res) => {
				setTimeout(() => {
					setIsLoading(false);
				}, 100);
				invalidateResolutionConnectedStores();

				configApi.setInitialPayload(res);
			});
		}

		// Remove all notices on any page mount
		notices.forEach((notice) => {
			removeNotice(notice.id);
		});

		return () => {
			//console.log('invalidatating')
			invalidateResolutionConnectedStores();
		};
	}, []);

	return (
		<SlotFillProvider>
			{pluginsArea}
			<div className={classnames("quillforms-layout", `quillforms-${props.pageKey}-page-layout`)}	>
				{!props.page.header ? (
					<Header />
				) : (
					<props.page.header {...props} />
				)}

				<div className="quillforms-layout__main">
					{(!props.page.template ||
						props.page.template === 'default') && <Sidebar />}
					{isLoading ? (
						<div
							className={css`
								display: flex;
								flex-wrap: wrap;
								width: 100%;
								min-height: 100vh;
								justify-content: center;
								align-items: center;
							` }
						>
							<Loader
								color="#8640e3"
								height={50}
								width={50}
							/>
						</div>
					) : (
						<Controller {...props} />
					)}
				</div>
			</div>
		</SlotFillProvider>
	);
};

const _PageLayout = () => {
	return (
		<>
			<Router history={getHistory()}>
				<Switch>
					{Object.entries(getAdminPages()).map(([key, page]) => {
						return (
							<Route
								key={page.path}
								path={page.path}
								exact={page.exact}
								render={(props) => (
									<Layout page={page} pageKey={key} {...props} />
								)}
							/>
						);
					})}
				</Switch>
			</Router>
		</>
	);
};

export default _PageLayout;
