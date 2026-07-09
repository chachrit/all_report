<style>
    .sm-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(17,24,39,0.35);
        z-index: 900;
    }
    .sm-backdrop.open { display: block; }
    .sm-modal {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 420px;
        max-width: calc(100vw - 32px);
        max-height: calc(100vh - 64px);
        overflow-y: auto;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 20px 50px rgba(17,24,39,0.25);
        z-index: 901;
        padding: 22px;
    }
    .sm-modal-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
    .sm-modal-header h3 { margin: 0; font-size: 15px; font-weight: 600; color: var(--text-primary); }
    .sm-modal-close { border: none; background: none; font-size: 18px; line-height: 1; color: var(--text-muted); cursor: pointer; padding: 0 4px; }
    .sm-modal-close:hover { color: var(--text-primary); }
    .sm-hint { font-size: 12px; color: var(--text-secondary); margin: 6px 0 16px; }
    .sm-roles { display: flex; gap: 8px; margin-bottom: 16px; }
    .sm-role-btn {
        flex: 1;
        border: 1px solid #ECEEF1;
        background: #F8F9FB;
        border-radius: 8px;
        padding: 8px 6px;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-secondary);
        cursor: pointer;
    }
    .sm-role-btn:hover { border-color: #D1D5DB; }
    .sm-role-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
    .sm-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .sm-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 10px;
        border: 1px solid #ECEEF1;
        border-radius: 8px;
        background: #fff;
        cursor: grab;
    }
    .sm-item.dragging { opacity: 0.4; }
    .sm-item .sm-handle { color: var(--text-muted); font-size: 13px; user-select: none; }
    .sm-item label { flex: 1; font-size: 13px; color: var(--text-primary); display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .sm-item input[type="checkbox"] { cursor: pointer; }
    .sm-item.is-hidden label { color: var(--text-muted); text-decoration: line-through; }
    .sm-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid #f0f0f0; }
    .sm-reset-btn { border: none; background: none; font-size: 12px; color: var(--text-secondary); cursor: pointer; text-decoration: underline; padding: 0; }
    .sm-reset-btn:hover { color: var(--text-primary); }
</style>
<div class="sm-backdrop" id="sectionManagerBackdrop">
    <div class="sm-modal" id="sectionManagerModal" role="dialog" aria-modal="true">
        <div class="sm-modal-header">
            <h3><?php echo htmlspecialchars(header_ui_text($headerUi, 'sections_panel_title')); ?></h3>
            <button type="button" class="sm-modal-close" aria-label="<?php echo htmlspecialchars(header_ui_text($headerUi, 'sections_close')); ?>" onclick="window.SectionManager && window.SectionManager.close()">&times;</button>
        </div>
        <div class="sm-hint"><?php echo htmlspecialchars(header_ui_text($headerUi, 'sections_panel_hint')); ?></div>
        <div class="sm-roles">
            <button type="button" class="sm-role-btn" data-role="ceo"><?php echo htmlspecialchars(header_ui_text($headerUi, 'role_ceo')); ?></button>
            <button type="button" class="sm-role-btn" data-role="manager"><?php echo htmlspecialchars(header_ui_text($headerUi, 'role_manager')); ?></button>
            <button type="button" class="sm-role-btn" data-role="operation"><?php echo htmlspecialchars(header_ui_text($headerUi, 'role_operation')); ?></button>
        </div>
        <ul class="sm-list" id="sectionManagerList"></ul>
        <div class="sm-footer">
            <button type="button" class="sm-reset-btn" id="sectionManagerReset"><?php echo htmlspecialchars(header_ui_text($headerUi, 'sections_reset')); ?></button>
        </div>
    </div>
</div>
