<div class="toolbox">
    <div class="toolbox-design">
    <h2>{"User information"|i18n( "design/standard/toolbar" )}</h2>

    <div class="toolbox-content">
        {let logged_in_count=fetch( user, logged_in_count )
             anonymous_count=fetch( user, anonymous_count )}
        {'There are %logged_in_count registered and %anonymous_count anonymous users online.'|i18n( 'design/standard/toolbar',,
          hash( '%logged_in_count', $logged_in_count, '%anonymous_count', $anonymous_count ) )}
        {/let}
    </div>

    </div>
</div>