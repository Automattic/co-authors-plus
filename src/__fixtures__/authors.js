export const selectedAuthors = [
	{
		value: 'ruby',
		display: 'Ruby Bridges',
	},
	{
		value: 'chanda',
		display: 'Chanda Prescod-Weinstein',
	},
	{
		value: 'imaraj',
		display: 'Imara Jones',
	},
	{
		value: 'echeng',
		display: 'Eugenia Cheng',
	},
];

export const newAuthorValue = 'questlove';

export const dropdownOptions = [
	{
		value: 'questlove',
		display: 'Ahmir Thompson',
	},
	{
		value: 'claudette',
		display: 'Claudette Colvin',
	},
];

/**
 * Raw author objects as returned by the REST endpoint, carrying the fields
 * `formatAuthorData` reads: id, displayName, userNicename, email, userType and
 * termId.
 */
export const rawAuthors = [
	{
		id: 5,
		termId: 42,
		displayName: 'Ruby Bridges',
		userNicename: 'ruby',
		email: 'ruby@example.com',
		userType: 'wpuser',
	},
	{
		id: 0,
		termId: 43,
		displayName: 'Claudette Colvin',
		userNicename: 'claudette',
		email: 'claudette@example.com',
		userType: 'guest-user',
	},
];
