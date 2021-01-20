{extends "$layout"}

{block name="content"}
    <section>
        <p>{l s='There was an error while processing your transaction.'}</p>
        <p>{l s='Here are the params:'}</p>
        <ul>
            {foreach from=$params key=name item=value}
                <li>{$name}: {$value}</li>
            {/foreach}
        </ul>
        <p>{l s="Contact support for more details."}</p>
    </section>
{/block}