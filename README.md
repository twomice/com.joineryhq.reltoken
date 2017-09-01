# Related Tokens

Provides tokens for any related contact, for all available contact tokens. E.g., to get the spouse's first name, or parent's marriage date.

## Usage

* Once installed, this extension will add to CiviCRM's </tokens> list one token
for each existing contact token, for each enabled relationship type. For example,
on a site with relationship types "Spouse" and "Parent / Child", additional
"First Name" tokens will be added, like so:
  * Related (Spouse)::First Name
  * Related (Parent)::First Name
  * Related (Child)::First Name
* Note that only one First Name token is added for the "Spouse" relationship type,
whereas two are added for the "Parent / Child" relationship type. This is because
"Spouse" relationships are reciprocal, where as "Parent / Child" relationships
are directional. Reciprocal relationship types, such as "Partner" or "Sibling"
are detected by virtue of having the same label for both contacts, and are
treated in the same way.
* Use these tokens as any other token. From the above example, the tokens will
provide the following values:
  * Related (Spouse)::First Name: _first name of spouse_
  * Related (Parent)::First Name: _first name of parent_
  * Related (Child)::First Name: _first name of child_
* If the contact has more than one relationship of the given type, the
relationship with the highest internal ID (typically the one most recently
created) is used.

## Similar extensions
Whereas "Related Tokens" adds _all available tokens_ -- including all custom fields and other fields, as explained above -- for each relationship type, the ["CiviToken" extension](https://github.com/eileenmcnaughton/nz.co.fuzion.civitoken) adds only 6 tokens (Display Name, First Name, Last Name, Phone, Email, Contact ID) for each relationship type, along with a variety of other customized tokens not derived from relationships.

## Tech details
1. Token strings are built from the values of `civicrm_relationship_type.name_a_b`
and `civicrm_relationship_type.name_b_a`. These values are normally fairly stable,
and this approach aims to avoid problems in dev/stage/prod environments where
relationship type IDs could differ from system to system.

## Room for improvement
This extension could be improved in many ways; here are some I've thought of but
not yet implemented:
1. Provide more control or better defaults for what happens when a contact has
more than one relationship of a given type. For example, many contacts may have
more than one "Parent/Child", and the _Related (Child)::First Name_ token
obviously falls short here; at present it will use the relationship with the
highest internal ID (typically the one most recently created), but it could be
more desirable in various situations to use the one with the newest start date,
or to concatenate them all into a comma-separated string, or any number of other
things.
2. This extension can add several hundred tokens to the list (Total Number of
Enabled Relationship x (1 or 2) x Total Number of Contact Tokens). It might help
support a configurable list of supported relationship types and/or contact tokens.
3. Perhaps there are other tokens that should be supported as well; suggestions
are welcome.
