# AuthorSelection
A component for adding and removing authors selected via search.

## Usage
```jsx
<AuthorsSelection
  selectedAuthors={ selectedAuthors }
  updateAuthors={ updateAuthors }
  roles={ roles }
  updateRole={ updateRole }
/>
```

## Props
| name            | type     | required | description                                |
|-----------------|----------|----------|--------------------------------------------|
| authors         | array    | yes      | Array of author objects.                   |
| setAuthorsStore | function | yes      | Callback setter for authors array updates. |
| roles           | object   | no       | Roles for this post, keyed by author term ID. |
| updateRole      | function | no       | Called with a term ID and the new role. The role field shows only when this is set. |
