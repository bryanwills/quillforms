/**
 * WordPress Dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * QuillForms Dependencies
 */
import { Button, ToggleControl } from '@quillforms/admin-components';

/**
 * Internal Dependencies
 */
import './style.scss';

/**
 * The public MCP endpoint.
 *
 * Derived from the REST root WordPress already exposes to scripts, so it stays
 * correct on subdirectory installs and on sites with a custom rest_url filter,
 * without core having to localize another value.
 */
const MCP_ENDPOINT_URL = (() => {
	if (typeof window !== 'undefined' && window.qfMcp?.endpointUrl) {
		return window.qfMcp.endpointUrl;
	}

	const root =
		typeof window !== 'undefined' && window.wpApiSettings?.root
			? window.wpApiSettings.root
			: '/wp-json/';

	return root.replace(/\/$/, '') + '/quillforms/v1/mcp';
})();

/**
 * Whether this WordPress exposes the Abilities API (6.9+).
 *
 * Localized by the server, which is the only side that can answer honestly.
 * Defaults to true so a missing value never shows a false alarm — a site that
 * really lacks the API fails visibly on the first test run instead.
 */
const HAS_ABILITIES_API =
	typeof window !== 'undefined' && window.qfMcp
		? !!window.qfMcp.abilitiesApi
		: true;

/**
 * Where each client's snippet goes.
 *
 * Every entry needs Node: this server authenticates with HTTP Basic, so a
 * client cannot talk to it directly — it goes through the WordPress MCP bridge,
 * which is launched with npx and reads its credentials from the environment.
 */
const CLIENT_TABS = [
	{
		key: 'claude-code',
		label: __('Claude Code', 'quillforms'),
		target: __('Run this in your terminal.', 'quillforms'),
	},
	{
		key: 'claude-desktop',
		label: __('Claude Desktop', 'quillforms'),
		target: __(
			'Add to claude_desktop_config.json — macOS: ~/Library/Application Support/Claude/ · Windows: %APPDATA%\\Claude\\',
			'quillforms'
		),
	},
	{
		key: 'cursor',
		label: __('Cursor', 'quillforms'),
		target: __(
			'Add to Cursor’s mcp.json (Settings → Tools & MCP).',
			'quillforms'
		),
	},
	{
		key: 'codex',
		label: __('OpenAI Codex', 'quillforms'),
		target: __('Add to ~/.codex/config.toml', 'quillforms'),
	},
	{
		key: 'other',
		label: __('Other', 'quillforms'),
		target: __(
			'Any MCP client that can launch a local command.',
			'quillforms'
		),
	},
];

const Settings = () => {
	const [settings, setSettings] = useState(null);
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState(null);
	const [copied, setCopied] = useState(false);

	const [testing, setTesting] = useState(false);
	const [steps, setSteps] = useState([]);
	const [discovered, setDiscovered] = useState(null);
	const [expanded, setExpanded] = useState(null);

	// Default to the platform the admin is sitting at, but let them switch:
	// people routinely generate a snippet for a different machine.
	const [isWindows, setIsWindows] = useState(() =>
		typeof navigator !== 'undefined'
			? /win/i.test(navigator.platform || '')
			: false
	);
	const [client, setClient] = useState('claude-code');
	const [snippetCopied, setSnippetCopied] = useState(false);
	const [username, setUsername] = useState('');
	const [appPassword, setAppPassword] = useState('');

	/**
	 * Send one JSON-RPC call to our own endpoint.
	 *
	 * Uses apiFetch so the logged-in user's cookie and nonce are attached: this
	 * exercises the real endpoint and the real per-tool permission checks,
	 * just authenticated as the current admin rather than by application
	 * password.
	 */
	const rpc = (method, params) =>
		apiFetch({
			path: '/quillforms/v1/mcp',
			method: 'POST',
			data: {
				jsonrpc: '2.0',
				id: Date.now(),
				method,
				...(params ? { params } : {}),
			},
		});

	/**
	 * Ask the live server which tools are currently registered.
	 *
	 * Reading this from the endpoint rather than from a settings field means
	 * the count can never drift from what a client would actually be offered.
	 */
	const refreshTools = () =>
		rpc('tools/list')
			.then((res) =>
				(res?.result?.tools ?? []).map((tool) => tool.name)
			)
			.catch(() => []);

	useEffect(() => {
		apiFetch({ path: '/qf/v1/settings?groups=mcp' })
			.then((res) => {
				const group = res?.mcp ?? {};
				const next = {
					enabled: !!group.mcp_enabled,
					allow_updates: !!group.mcp_allow_updates,
					endpoint_url: MCP_ENDPOINT_URL,
					abilities_api: HAS_ABILITIES_API,
					tools: [],
				};

				if (!next.enabled) {
					setSettings(next);
					setLoading(false);
					return;
				}

				refreshTools().then((tools) => {
					setSettings({ ...next, tools });
					setLoading(false);
				});
			})
			.catch((err) => {
				setError(
					err?.message ??
						__('Could not load settings.', 'quillforms')
				);
				setLoading(false);
			});
	}, []);

	const save = (changes) => {
		if (!settings) return;

		// Optimistic: the toggle should feel instant, and a failure restores
		// the previous value below.
		const previous = settings;
		const next = { ...settings, ...changes };
		setSettings(next);
		setSaving(true);
		setError(null);

		// Settings are read back grouped but written flat, the same way the
		// General tab does it: the server stores one flat option and only
		// groups the response.
		apiFetch({
			path: '/qf/v1/settings',
			method: 'POST',
			data: {
				mcp_enabled: next.enabled,
				mcp_allow_updates: next.allow_updates,
			},
		})
			.then(() => (next.enabled ? refreshTools() : []))
			.then((tools) => {
				setSettings({ ...next, tools });
				setSaving(false);
			})
			.catch((err) => {
				setSettings(previous);
				setError(
					err?.message ??
						__('Could not save settings.', 'quillforms')
				);
				setSaving(false);
			});
	};

	const copyEndpoint = () => {
		if (!settings?.endpoint_url) return;
		navigator.clipboard?.writeText(settings.endpoint_url).then(() => {
			setCopied(true);
			setTimeout(() => setCopied(false), 2000);
		});
	};

	/**
	 * Walk the same handshake an MCP client performs, and show each step.
	 *
	 * The read-only quillforms_list_forms call is used as the sample tool
	 * deliberately: it proves a tool really executes without changing anything
	 * on the site.
	 */
	const runTest = async () => {
		setTesting(true);
		setDiscovered(null);
		setExpanded(null);

		const record = (step) =>
			setSteps((prev) => [...prev.filter((s) => s.label !== step.label), step]);

		const fail = (label, err) => {
			record({
				label,
				status: 'fail',
				detail:
					err?.message ??
					__('Request failed.', 'quillforms'),
			});
			setTesting(false);
		};

		setSteps([
			{
				label: __('initialize', 'quillforms'),
				status: 'running',
				detail: __('Opening session…', 'quillforms'),
			},
		]);

		// 1. initialize — the handshake every MCP client starts with.
		let init;
		try {
			init = await rpc('initialize', {});
		} catch (err) {
			return fail(__('initialize', 'quillforms'), err);
		}

		if (init?.error) {
			return fail(__('initialize', 'quillforms'), init.error);
		}

		record({
			label: __('initialize', 'quillforms'),
			status: 'ok',
			detail: sprintf(
				/* translators: 1: server name, 2: protocol version. */
				__('Connected to %1$s (protocol %2$s)', 'quillforms'),
				init?.result?.serverInfo?.name ?? 'Quill Forms',
				init?.result?.protocolVersion ?? '—'
			),
			payload: JSON.stringify(init.result, null, 2),
		});

		// 2. tools/list — what the client would be offered.
		record({
			label: __('tools/list', 'quillforms'),
			status: 'running',
			detail: __('Fetching tools…', 'quillforms'),
		});

		let list;
		try {
			list = await rpc('tools/list');
		} catch (err) {
			return fail(__('tools/list', 'quillforms'), err);
		}

		if (list?.error) {
			return fail(__('tools/list', 'quillforms'), list.error);
		}

		const tools = list?.result?.tools ?? [];
		setDiscovered(tools);

		record({
			label: __('tools/list', 'quillforms'),
			status: tools.length ? 'ok' : 'fail',
			detail: tools.length
				? sprintf(
						/* translators: %d: number of tools. */
						__('%d tools available to you', 'quillforms'),
						tools.length
				  )
				: __(
						'No tools returned. Is the server enabled?',
						'quillforms'
				  ),
		});

		if (!tools.length) {
			setTesting(false);
			return;
		}

		// 3. tools/call — prove one actually runs end to end.
		record({
			label: __('tools/call', 'quillforms'),
			status: 'running',
			detail: __('Running quillforms_list_forms…', 'quillforms'),
		});

		let call;
		try {
			call = await rpc('tools/call', {
				name: 'quillforms_list_forms',
				arguments: { per_page: 3 },
			});
		} catch (err) {
			return fail(__('tools/call', 'quillforms'), err);
		}

		if (call?.error) {
			return fail(__('tools/call', 'quillforms'), call.error);
		}

		const isError = call?.result?.isError;
		const text = call?.result?.content?.[0]?.text ?? '';
		const total = call?.result?.structuredContent?.total;

		record({
			label: __('tools/call', 'quillforms'),
			status: isError ? 'fail' : 'ok',
			detail: isError
				? text
				: sprintf(
						/* translators: %s: number of forms found. */
						__(
							'quillforms_list_forms returned %s form(s)',
							'quillforms'
						),
						total ?? '?'
				  ),
			payload: text,
		});

		setTesting(false);
	};

	if (loading) {
		return (
			<div className="quillforms-mcp-settings">
				{__('Loading…', 'quillforms')}
			</div>
		);
	}

	if (!settings) {
		return (
			<div className="quillforms-mcp-settings">
				<div className="quillforms-mcp-settings__error">
					{error ?? __('Settings unavailable.', 'quillforms')}
				</div>
			</div>
		);
	}

	const url = settings.endpoint_url;
	const user = username.trim() || 'your-username';
	// Application passwords are displayed in groups of four; people paste them
	// with the spaces intact and the bridge would send them verbatim.
	const pass =
		appPassword.replace(/\s+/g, '') || 'your-application-password';

	/**
	 * Build the config for the selected client and platform.
	 *
	 * Every client routes through the same npx bridge, because the server
	 * authenticates with HTTP Basic rather than a bearer token. On Windows the
	 * command is wrapped in `cmd /c`, which is how npx is reachable there.
	 */
	const buildSnippet = () => {
		const env = {
			WP_API_URL: url,
			WP_API_USERNAME: user,
			WP_API_PASSWORD: pass,
		};

		if (client === 'claude-code') {
			const lines = [
				'claude mcp add quillforms \\',
				`  --env WP_API_URL=${url} \\`,
				`  --env WP_API_USERNAME=${user} \\`,
				`  --env WP_API_PASSWORD=${pass} \\`,
			];
			return isWindows
				? [
						...lines,
						'  -- cmd /c npx -y @automattic/mcp-wordpress-remote',
				  ].join('\n')
				: [
						...lines,
						'  -- npx -y @automattic/mcp-wordpress-remote',
				  ].join('\n');
		}

		const server = isWindows
			? {
					command: 'cmd',
					args: [
						'/c',
						'npx',
						'-y',
						'@automattic/mcp-wordpress-remote',
					],
					env,
			  }
			: {
					command: 'npx',
					args: ['-y', '@automattic/mcp-wordpress-remote'],
					env,
			  };

		if (client === 'claude-desktop' || client === 'cursor') {
			return JSON.stringify(
				{ mcpServers: { quillforms: server } },
				null,
				2
			);
		}

		if (client === 'codex') {
			const argLines = server.args
				.map((arg) => `  "${arg}",`)
				.join('\n')
				.replace(/,$/, '');

			return [
				'[mcp_servers.quillforms]',
				`command = "${server.command}"`,
				'args = [',
				argLines,
				']',
				'',
				'[mcp_servers.quillforms.env]',
				`WP_API_URL = "${url}"`,
				`WP_API_USERNAME = "${user}"`,
				`WP_API_PASSWORD = "${pass}"`,
			].join('\n');
		}

		return [
			`Endpoint: ${url}`,
			'Transport: stdio via @automattic/mcp-wordpress-remote',
			'Auth: HTTP Basic (WordPress application password)',
			`WP_API_USERNAME: ${user}`,
			`WP_API_PASSWORD: ${pass}`,
		].join('\n');
	};

	const clientConfig = buildSnippet();
	const activeTab = CLIENT_TABS.find((t) => t.key === client);

	const copySnippet = () => {
		navigator.clipboard?.writeText(clientConfig).then(() => {
			setSnippetCopied(true);
			setTimeout(() => setSnippetCopied(false), 2000);
		});
	};

	return (
		<div className="quillforms-mcp-settings">
			<p className="quillforms-mcp-settings__intro">
				{__(
					'Connect Quill Forms to Claude, ChatGPT and other AI assistants over the Model Context Protocol, so you can build and manage forms by describing what you want.',
					'quillforms'
				)}
			</p>

			{!settings.abilities_api && (
				<div className="quillforms-mcp-settings__warning">
					{__(
						'This site is running a version of WordPress without the Abilities API. WordPress 6.9 or later is required.',
						'quillforms'
					)}
				</div>
			)}

			{error && (
				<div className="quillforms-mcp-settings__error">{error}</div>
			)}

			<div className="quillforms-mcp-settings__row">
				<ToggleControl
					checked={settings.enabled}
					onChange={() => save({ enabled: !settings.enabled })}
					disabled={saving || !settings.abilities_api}
				/>
				<div className="quillforms-mcp-settings__label">
					<strong>{__('Enable MCP Server', 'quillforms')}</strong>
					<span>
						{__(
							'Lets authorised AI clients read your forms and responses.',
							'quillforms'
						)}
					</span>
				</div>
			</div>

			<div className="quillforms-mcp-settings__row">
				<ToggleControl
					checked={settings.allow_updates}
					onChange={() =>
						save({ allow_updates: !settings.allow_updates })
					}
					disabled={saving || !settings.enabled}
				/>
				<div className="quillforms-mcp-settings__label">
					<strong>{__('Allow updates', 'quillforms')}</strong>
					<span>
						{__(
							'Additionally lets AI clients create, edit and delete forms. Leave off for read-only access.',
							'quillforms'
						)}
					</span>
				</div>
			</div>

			{settings.enabled && (
				<>
					<div className="quillforms-mcp-settings__section">
						<h3>{__('Server URL', 'quillforms')}</h3>
						<div className="quillforms-mcp-settings__endpoint">
							<code>{settings.endpoint_url}</code>
							<Button isDefault onClick={copyEndpoint}>
								{copied
									? __('Copied', 'quillforms')
									: __('Copy', 'quillforms')}
							</Button>
						</div>
						<p className="quillforms-mcp-settings__hint">
							{__(
								'Authenticate with an Application Password: Users → Profile → Application Passwords. Requires HTTPS.',
								'quillforms'
							)}
						</p>
					</div>

					<div className="quillforms-mcp-settings__section">
						<h3>{__('Client configuration', 'quillforms')}</h3>

						<div className="quillforms-mcp-settings__creds">
							<label>
								<span>
									{__('Username', 'quillforms')}
								</span>
								<input
									type="text"
									value={username}
									placeholder={__(
										'your-username',
										'quillforms'
									)}
									onChange={(e) =>
										setUsername(e.target.value)
									}
								/>
							</label>
							<label>
								<span>
									{__(
										'Application password',
										'quillforms'
									)}
								</span>
								<input
									type="text"
									value={appPassword}
									placeholder="xxxx xxxx xxxx xxxx"
									onChange={(e) =>
										setAppPassword(e.target.value)
									}
								/>
							</label>
						</div>
						<p className="quillforms-mcp-settings__hint">
							{__(
								'Optional — fill these in and the snippet below is ready to paste. Create one at Users → Profile → Application Passwords. Typed here, it is only used to build the snippet in your browser and is never sent to the server.',
								'quillforms'
							)}
						</p>

						<div className="quillforms-mcp-settings__switcher">
							<span className="quillforms-mcp-settings__switcher-label">
								{__('Client runs on:', 'quillforms')}
							</span>
							{[
								{
									label: __('Windows', 'quillforms'),
									value: true,
								},
								{
									label: __(
										'macOS / Linux',
										'quillforms'
									),
									value: false,
								},
							].map((os) => (
								<button
									key={os.label}
									type="button"
									className={
										'quillforms-mcp-settings__pill' +
										(isWindows === os.value
											? ' is-active'
											: '')
									}
									onClick={() => setIsWindows(os.value)}
								>
									{os.label}
								</button>
							))}
						</div>

						<div className="quillforms-mcp-settings__switcher">
							{CLIENT_TABS.map((tab) => (
								<button
									key={tab.key}
									type="button"
									className={
										'quillforms-mcp-settings__pill' +
										(client === tab.key
											? ' is-active'
											: '')
									}
									onClick={() => setClient(tab.key)}
								>
									{tab.label}
								</button>
							))}
						</div>

						<p className="quillforms-mcp-settings__target">
							{activeTab?.target}
						</p>

						<pre className="quillforms-mcp-settings__code">
							{clientConfig}
						</pre>

						<div className="quillforms-mcp-settings__snippet-actions">
							<Button isDefault onClick={copySnippet}>
								{snippetCopied
									? __('Copied', 'quillforms')
									: __('Copy snippet', 'quillforms')}
							</Button>
							<span className="quillforms-mcp-settings__hint">
								{appPassword.trim()
									? __(
											'This snippet contains your application password. Treat it like a password — do not commit it to a repository, paste it into a shared chat, or include it in a screenshot.',
											'quillforms'
									  )
									: __(
											'Enter a username and application password above to get a ready-to-paste snippet.',
											'quillforms'
									  )}
							</span>
						</div>
						<div className="quillforms-mcp-settings__compat">
							<div>
								<strong>
									{__('Works with', 'quillforms')}
								</strong>
								<span>
									{__(
										'Claude Desktop, Cursor, and any client that can run a local command — they all support this npx bridge.',
										'quillforms'
									)}
								</span>
							</div>
							<div>
								<strong>
									{__(
										'Not yet supported',
										'quillforms'
									)}
								</strong>
								<span>
									{__(
										'Clients that connect straight over HTTP with a bearer token, such as ChatGPT connectors or "claude mcp add --transport http". These need an API key, which this addon does not issue yet.',
										'quillforms'
									)}
								</span>
							</div>
						</div>
					</div>

					<div className="quillforms-mcp-settings__section">
						<div className="quillforms-mcp-settings__section-head">
							<h3>{__('Test connection', 'quillforms')}</h3>
							<Button
								isPrimary
								onClick={runTest}
								disabled={testing}
							>
								{testing
									? __('Testing…', 'quillforms')
									: __('Run test', 'quillforms')}
							</Button>
						</div>
						<p className="quillforms-mcp-settings__hint">
							{__(
								'Runs the same three calls an AI client makes — initialize, tools/list and tools/call. The sample call only reads forms; nothing is changed.',
								'quillforms'
							)}
						</p>

						{steps.length > 0 && (
							<ul className="quillforms-mcp-settings__steps">
								{steps.map((step) => (
									<li
										key={step.label}
										className={
											'is-' + step.status
										}
									>
										<div className="quillforms-mcp-settings__step-head">
											<span className="quillforms-mcp-settings__step-icon">
												{step.status === 'ok'
													? '✓'
													: step.status === 'fail'
													? '✕'
													: '…'}
											</span>
											<code>{step.label}</code>
											<span className="quillforms-mcp-settings__step-detail">
												{step.detail}
											</span>
											{step.payload && (
												<button
													type="button"
													className="quillforms-mcp-settings__step-toggle"
													onClick={() =>
														setExpanded(
															expanded ===
																step.label
																? null
																: step.label
														)
													}
												>
													{expanded === step.label
														? __(
																'Hide response',
																'quillforms'
														  )
														: __(
																'Show response',
																'quillforms'
														  )}
												</button>
											)}
										</div>
										{expanded === step.label &&
											step.payload && (
												<pre className="quillforms-mcp-settings__code">
													{step.payload}
												</pre>
											)}
									</li>
								))}
							</ul>
						)}
					</div>

					<div className="quillforms-mcp-settings__section">
						<h3>
							{__('Available tools', 'quillforms')}{' '}
							<span className="quillforms-mcp-settings__count">
								{(discovered ?? settings.tools).length}
							</span>
						</h3>
						{discovered ? (
							<ul className="quillforms-mcp-settings__tool-cards">
								{discovered.map((tool) => (
									<li key={tool.name}>
										<div className="quillforms-mcp-settings__tool-head">
											<code>{tool.name}</code>
											<span
												className={
													'quillforms-mcp-settings__badge is-' +
													(tool.annotations
														?.readOnlyHint
														? 'read'
														: tool.annotations
																?.destructiveHint
														? 'destructive'
														: 'write')
												}
											>
												{tool.annotations?.readOnlyHint
													? __('read', 'quillforms')
													: tool.annotations
															?.destructiveHint
													? __(
															'destructive',
															'quillforms'
													  )
													: __(
															'write',
															'quillforms'
													  )}
											</span>
										</div>
										<p>{tool.description}</p>
									</li>
								))}
							</ul>
						) : (
							<ul className="quillforms-mcp-settings__tools">
								{settings.tools.map((tool) => (
									<li key={tool}>
										<code>{tool}</code>
									</li>
								))}
							</ul>
						)}
					</div>
				</>
			)}
		</div>
	);
};

export default Settings;
