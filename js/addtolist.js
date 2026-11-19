// "Add to shopping list" panel, shared by the recipe page (one recipe, with "have it" ticks)
// and the cookbook page (several recipes at once). Requires js/api.js and js/app.js.
//
// recipes: [{ id, title, scaleMode?, scaleValue?, factor?, ingredients? }]
// With one recipe that carries ingredients, each ingredient gets a tick box — untick what you
// already have (the Senior Family Cookbook's check-off step). Optional ones start unticked.

async function showAddToList(box, recipes, onClose) {
  box.classList.remove('hidden');
  box.innerHTML = '<p class="muted">Loading your lists…</p>';
  let lists;
  try {
    lists = (await apiGet('listLists')).lists.filter(l => l.status === 'active' && l.role !== 'viewer');
  } catch (e) {
    box.innerHTML = `<p class="field-error">${escapeHtml(e.message)}</p>`;
    return;
  }
  const single = recipes.length === 1 && Array.isArray(recipes[0].ingredients) ? recipes[0] : null;
  const last = lastListId();
  const selected = lists.some(l => l.id === last) ? last : (lists[0] ? lists[0].id : 'new');

  box.innerHTML = `
    <h2 style="margin-bottom:4px">Add to shopping list</h2>
    <p class="muted">${single ? escapeHtml(single.title) + (single.scaleLabel ? ` — ${escapeHtml(single.scaleLabel)}` : '')
      : `${recipes.length} recipe${recipes.length === 1 ? '' : 's'}, each at your saved amount (or as written)`}</p>
    <label for="atl-list">List</label>
    <select id="atl-list">${lists.map(l => `<option value="${l.id}"${l.id === selected ? ' selected' : ''}>${escapeHtml(l.title)}</option>`).join('')}
      <option value="new"${selected === 'new' ? ' selected' : ''}>+ New list…</option></select>
    <div id="atl-new-wrap" class="${selected === 'new' ? '' : 'hidden'}">
      <label for="atl-new">Name the new list <span class="muted">(what it's for)</span></label>
      <input type="text" id="atl-new" maxlength="150" placeholder="e.g. Weekly Groceries, Thanksgiving Dinner">
    </div>
    ${single ? `<fieldset style="border:0;padding:0;margin:12px 0 0"><legend style="font-weight:600">Untick anything you already have</legend>
      ${single.ingredients.map(i => `<label class="check" style="margin:6px 0"><input type="checkbox" data-ing="${i.id}"${i.optional ? '' : ' checked'}>
        <span>${escapeHtml(scaledIngredientText(i, single.factor || 1))}${i.optional ? ' <span class="muted">(optional)</span>' : ''}</span></label>`).join('')}
    </fieldset>` : ''}
    <div class="field-error" id="atl-msg" role="alert"></div>
    <div class="actions"><button type="button" class="btn" id="atl-add">Add to list</button>
      <button type="button" class="btn secondary" id="atl-cancel">Cancel</button></div>
    <div id="atl-done"></div>`;

  const sel = box.querySelector('#atl-list');
  const newWrap = box.querySelector('#atl-new-wrap');
  const msg = box.querySelector('#atl-msg');
  sel.addEventListener('change', () => {
    newWrap.classList.toggle('hidden', sel.value !== 'new');
    if (sel.value === 'new') { box.querySelector('#atl-new').focus(); }
  });
  box.querySelector('#atl-cancel').addEventListener('click', () => { box.classList.add('hidden'); if (onClose) { onClose(); } });
  box.querySelector('#atl-add').addEventListener('click', async ev => {
    const btn = ev.currentTarget;
    msg.textContent = '';
    btn.disabled = true;
    try {
      let listId = sel.value === 'new' ? null : Number(sel.value);
      let listTitle = listId ? lists.find(l => l.id === listId).title : '';
      if (!listId) {
        listTitle = box.querySelector('#atl-new').value.trim();
        if (!listTitle) { throw new ApiError('Name the new list', 'title', 400); }
        listId = (await apiPost('createList', { title: listTitle })).id;
      }
      let added = 0;
      let already = 0;
      for (const r of recipes) {
        const payload = { listId, recipeId: r.id };
        if (single) {
          Object.assign(payload, {
            scaleMode: r.scaleMode, scaleValue: r.scaleValue,
            excludedIngredientIds: [...box.querySelectorAll('[data-ing]')].filter(c => !c.checked).map(c => Number(c.dataset.ing)),
          });
        }
        try {
          await apiPost('addRecipeToList', payload);
          added++;
        } catch (e) {
          if (e.status !== 409) { throw e; }
          if (single && confirm(`${r.title} is already on "${listTitle}".\n\nUpdate it to this amount and these choices?`)) {
            await apiPost('updateListRecipe', { id: e.data.listRecipeId, scaleMode: payload.scaleMode, scaleValue: payload.scaleValue,
              excludedIngredientIds: payload.excludedIngredientIds });
            added++;
          } else {
            already++;
          }
        }
      }
      rememberList(listId);
      box.querySelector('#atl-done').innerHTML = `<p class="banner" style="margin:12px 0 0">
        ${added ? `Added to <strong>${escapeHtml(listTitle)}</strong>.` : ''}
        ${already ? ` ${already} already on that list — left as they were.` : ''}
        <a href="list.html?id=${listId}">Open the list</a></p>`;
      btn.disabled = false;
    } catch (e) {
      msg.textContent = e.message;
      btn.disabled = false;
    }
  });
}
