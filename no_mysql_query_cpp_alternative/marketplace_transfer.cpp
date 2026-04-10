/*
 * marketplace_transfer_item - C++ quest function alternative to mysql_query by Mijago
 * 
 * No mysql-client package needed. No mysql_query/split in questlib.lua needed.
 * No mysql_query/unpack in quest_functions needed.
 * 
 * 1. Add the function below to src/game/src/questlua_item.cpp
 *    (paste it before RegisterITEMFunctionTable)
 *
 * 2. Register it in the item_functions[] table inside RegisterITEMFunctionTable:
 *    { "marketplace_transfer", item_marketplace_transfer },
 *
 * 3. Add "item.marketplace_transfer" to quest_functions file
 *
 * 4. Rebuild the game binary
 *
 * 5. Use in quest:
 *    quest marketplace begin
 *        state start begin
 *            when 9010.take begin
 *                say_title("Web Marketplace")
 *                say("")
 *                say("Transfer this item to the web?")
 *                say("")
 *                say_item_vnum(item.get_vnum())
 *                say("")
 *
 *                if select("Transfer to Web", "Cancel") ~= 1 then
 *                    return
 *                end
 *
 *                local result = item.marketplace_transfer()
 *                if result == 1 then
 *                    say_title("Web Marketplace")
 *                    say("")
 *                    say("Done! Check the website.")
 *                else
 *                    say_title("Error")
 *                    say("Transfer failed.")
 *                end
 *            end
 *        end
 *    end
 */

// paste this in src/game/src/questlua_item.cpp before RegisterITEMFunctionTable()

	int item_marketplace_transfer(lua_State* L)
	{
		CQuestManager& q = CQuestManager::instance();
		LPITEM pItem = q.GetCurrentItem();
		LPCHARACTER ch = q.GetCurrentCharacterPtr();

		if (!pItem || !ch || !ch->GetDesc())
		{
			lua_pushnumber(L, 0);
			return 1;
		}

		DWORD dwVnum = pItem->GetVnum();
		const char* szName = pItem->GetName();
		DWORD dwCount = pItem->GetCount();
		DWORD dwPlayerID = ch->GetPlayerID();
		DWORD dwAccountID = ch->GetDesc()->GetAccountTable().id;
		const char* szPlayerName = ch->GetName();

		long s0 = pItem->GetSocket(0);
		long s1 = pItem->GetSocket(1);
		long s2 = pItem->GetSocket(2);

		char szQuery[2048];
		snprintf(szQuery, sizeof(szQuery),
			"INSERT INTO marketplace_items "
			"(owner_id,account_id,owner_name,item_vnum,item_name,item_count,"
			"socket0,socket1,socket2,"
			"attrtype0,attrvalue0,attrtype1,attrvalue1,"
			"attrtype2,attrvalue2,attrtype3,attrvalue3,"
			"attrtype4,attrvalue4,attrtype5,attrvalue5,"
			"attrtype6,attrvalue6,status,created_at) "
			"VALUES (%u,%u,'%s',%u,'%s',%u,"
			"%ld,%ld,%ld,"
			"%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,"
			"'inventory',NOW())",
			dwPlayerID, dwAccountID, szPlayerName,
			dwVnum, szName, dwCount,
			s0, s1, s2,
			pItem->GetAttributeType(0), pItem->GetAttributeValue(0),
			pItem->GetAttributeType(1), pItem->GetAttributeValue(1),
			pItem->GetAttributeType(2), pItem->GetAttributeValue(2),
			pItem->GetAttributeType(3), pItem->GetAttributeValue(3),
			pItem->GetAttributeType(4), pItem->GetAttributeValue(4),
			pItem->GetAttributeType(5), pItem->GetAttributeValue(5),
			pItem->GetAttributeType(6), pItem->GetAttributeValue(6)
		);

		std::unique_ptr<SQLMsg> pmsg(DBManager::instance().DirectQuery(szQuery));

		if (!pmsg->Get() || pmsg->Get()->uiAffectedRows == 0)
		{
			lua_pushnumber(L, 0);
			return 1;
		}

		// notification
		char szNotif[512];
		snprintf(szNotif, sizeof(szNotif),
			"INSERT INTO marketplace_notifications (account_id,message,created_at) "
			"VALUES (%u,'Item \"%s\" added to web inventory.',NOW())",
			dwAccountID, szName
		);
		DBManager::instance().DirectQuery(szNotif);

		// remove item
		ITEM_MANAGER::instance().RemoveItem(pItem, "MARKETPLACE_TRANSFER");
		q.ClearCurrentItem();

		lua_pushnumber(L, 1);
		return 1;
	}

// then in RegisterITEMFunctionTable(), add to item_functions[]:
// { "marketplace_transfer", item_marketplace_transfer },

// and add to quest_functions file:
// item.marketplace_transfer
