const McpIcon = () => {
	return (
		<svg
			width="20"
			height="20"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
		>
			{/* Two nodes linked to a hub: a server other clients connect to. */}
			<circle cx="5" cy="6" r="2.4" fill="currentColor" />
			<circle cx="5" cy="18" r="2.4" fill="currentColor" />
			<circle cx="18.5" cy="12" r="3.2" fill="currentColor" />
			<path
				d="M7.3 7.2 15.6 10.6M7.3 16.8 15.6 13.4"
				stroke="currentColor"
				strokeWidth="1.8"
				strokeLinecap="round"
			/>
		</svg>
	);
};

export default McpIcon;
