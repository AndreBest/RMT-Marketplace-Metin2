# !!!THIS IS JUST A CONCEPT!!!

You need to implement proper authentication or use the existing one of your website and payment processing.

I used mysql_query by Mijango but if you dont have it you can just use a C++ function, call it and it will work the same.

# Sockets Fix:
CheckItemSocket fix - ClientManager.cpp

CheckItemSocket overwrites stone vnums with 1 (empty slot) when delivering
items via item_award. This fix skips it when sockets already have values.

src/db/src/ClientManager.cpp

Find:
    ItemAwardManager::instance().CheckItemSocket(*pItemAward, *pItemTable);

Replace with:
    if (pItemAward->dwSocket0 <= 1 && pItemAward->dwSocket1 <= 1 && pItemAward->dwSocket2 <= 1)
        ItemAwardManager::instance().CheckItemSocket(*pItemAward, *pItemTable);
