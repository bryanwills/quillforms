/**
 * External Dependencies
 */
import { NavLink, withRouter } from '@quillforms/navigation';

/**
 * Internal Dependencies
 */
import Logo from '../logo';

const FormAdminBar = ( { formId } ) => {
	return (
		<div className="admin-components-form-admin-bar">
			<div className="admin-components-form-admin-bar__logo">
				<Logo />
			</div>
			<NavLink
				isActive={ ( _match, location ): boolean | void => {
					if ( location.pathname === `/forms/${ formId }/builder` ) {
						return true;
					}
				} }
				activeClassName="selected"
				to={ `/admin.php?page=quillforms&path=/forms/${ formId }/builder` }
			>
				Design
			</NavLink>
			<NavLink
				isActive={ ( _match, location ): boolean | void => {
					if (
						location.pathname === `/forms/${ formId }/integrations`
					) {
						return true;
					}
				} }
				activeClassName="selected"
				to={ `/admin.php?page=quillforms&path=/forms/${ formId }/integrations` }
			>
				Integrations
			</NavLink>
			<NavLink
				isActive={ ( _match, location ): boolean | void => {
					if ( location.pathname === `/forms/${ formId }/entries` ) {
						return true;
					}
				} }
				activeClassName="selected"
				to={ `/admin.php?page=quillforms&path=/forms/${ formId }/entries` }
			>
				Entries
			</NavLink>
			<NavLink
				isActive={ ( _match, location ): boolean | void => {
					if ( location.pathname === `/forms/${ formId }/reports` ) {
						return true;
					}
				} }
				activeClassName="selected"
				to={ `/admin.php?page=quillforms&path=/forms/${ formId }/reports` }
			>
				Reports
			</NavLink>
		</div>
	);
};
export default withRouter( FormAdminBar );